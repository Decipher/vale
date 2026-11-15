<?php

/**
 * @file
 * Mirrors the Drupal.org releases of Vale to GitHub Releases.
 *
 * Drupal.org is the source of truth. This script reads the published releases
 * from Drupal.org and creates a GitHub Release for each one whose tag is on
 * GitHub but has no release yet. It never creates tags, and it never edits or
 * deletes a release. Nothing goes from GitHub to Drupal.org.
 *
 * Usage: php scripts/mirror-releases.php [--dry-run]
 *
 * The repository comes from GITHUB_REPOSITORY and defaults to Decipher/vale.
 * The gh CLI must be logged in, or GH_TOKEN must be set.
 */

declare(strict_types=1);

exit(mirror_main($_SERVER['argv'] ?? []));

/**
 * Runs the mirror and returns the exit code.
 *
 * @param array<int, string> $argv
 *   The command line arguments.
 *
 * @return int
 *   The exit code.
 */
function mirror_main(array $argv): int {
  $dry_run = in_array('--dry-run', $argv, TRUE);
  $repository = getenv('GITHUB_REPOSITORY') ?: 'Decipher/vale';

  try {
    $releases = mirror_fetch_drupal_releases();
    $existing = mirror_fetch_github_releases($repository);
  }
  catch (RuntimeException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    return 1;
  }

  $latest = mirror_highest_stable(array_keys($releases));
  $prefix = $dry_run ? '[dry run] ' : '';
  $planned = 0;

  // Create the oldest release first, so the GitHub feed lists them in order.
  uksort($releases, mirror_compare_versions(...));
  foreach ($releases as $version => $release) {
    if (isset($existing[$version])) {
      if ($existing[$version]) {
        echo "Notice: {$version} has a draft release on GitHub. Skipped, drafts are left alone." . PHP_EOL;
      }
      continue;
    }

    try {
      $tag_exists = mirror_github_tag_exists($repository, $version);
    }
    catch (RuntimeException $e) {
      fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
      return 1;
    }
    if (!$tag_exists) {
      echo "Notice: the tag {$version} is not on GitHub yet. Skipped." . PHP_EOL;
      continue;
    }

    $prerelease = mirror_is_prerelease($version);
    $is_latest = $version === $latest;
    $flags = [];
    if ($prerelease) {
      $flags[] = 'prerelease';
    }
    if ($is_latest) {
      $flags[] = 'latest';
    }
    $label = $flags ? ' (' . implode(', ', $flags) . ')' : '';
    echo "{$prefix}Create the GitHub release {$version}{$label}." . PHP_EOL;
    $planned++;

    if ($dry_run) {
      continue;
    }

    try {
      mirror_create_github_release($repository, $version, $release['notes'], $prerelease, $is_latest);
    }
    catch (RuntimeException $e) {
      fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
      return 1;
    }
  }

  if ($planned === 0) {
    echo 'Every Drupal.org release already has a GitHub release.' . PHP_EOL;
  }

  return 0;
}

/**
 * Fetches the published releases of the project from Drupal.org.
 *
 * @return array<string, array{notes: string}>
 *   The release notes for GitHub, keyed by version.
 */
function mirror_fetch_drupal_releases(): array {
  $url = 'https://www.drupal.org/api-d7/node.json?' . http_build_query([
    'type' => 'project_release',
    // The node ID of the vale project on Drupal.org.
    'field_release_project' => 3632088,
    'status' => 1,
    'sort' => 'created',
    'direction' => 'DESC',
    'limit' => 50,
  ]);

  $releases = [];
  $seen = [];
  while ($url !== NULL) {
    // A broken next link could loop for ever, so each page is read once.
    if (isset($seen[$url])) {
      break;
    }
    $seen[$url] = TRUE;

    $data = mirror_http_get_json($url);
    if (!isset($data['list']) || !is_array($data['list'])) {
      throw new RuntimeException("The Drupal.org response from {$url} has no list of releases.");
    }

    foreach ($data['list'] as $node) {
      $version = $node['field_release_version'] ?? NULL;
      if (!is_string($version) || $version === '') {
        throw new RuntimeException('A Drupal.org release has no version.');
      }
      // Development releases have no tag.
      if (str_ends_with($version, '-dev')) {
        continue;
      }
      $body = $node['body']['value'] ?? '';
      $page = 'https://www.drupal.org/project/vale/releases/' . $version;
      $notes = trim(is_string($body) ? $body : '');
      $notes .= ($notes === '' ? '' : "\n\n") . "Release notes on Drupal.org: {$page}\n";
      $releases[$version] = ['notes' => $notes];
    }

    $url = isset($data['next']) && is_string($data['next']) ? $data['next'] : NULL;
  }

  return $releases;
}

/**
 * Fetches a URL and decodes its JSON body.
 *
 * @param string $url
 *   The URL.
 *
 * @return array<mixed>
 *   The decoded JSON.
 */
function mirror_http_get_json(string $url): array {
  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => "Accept: application/json\r\nUser-Agent: vale-release-mirror\r\n",
      'timeout' => 60,
      'ignore_errors' => TRUE,
    ],
  ]);

  $body = @file_get_contents($url, FALSE, $context);
  if ($body === FALSE) {
    throw new RuntimeException("Could not fetch {$url}.");
  }

  // The last status line is the one for the final response after redirects.
  // PHP 8.4 adds a function for the headers. PHP 8.3 only has the variable.
  if (function_exists('http_get_last_response_headers')) {
    $headers = http_get_last_response_headers() ?? [];
  }
  else {
    $headers = $http_response_header;
  }
  $status = 0;
  foreach ($headers as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
      $status = (int) $matches[1];
    }
  }
  if ($status !== 200) {
    throw new RuntimeException("Fetching {$url} returned HTTP {$status}.");
  }

  try {
    $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
  }
  catch (JsonException $e) {
    throw new RuntimeException("The response from {$url} is not valid JSON: " . $e->getMessage(), $e->getCode(), $e);
  }
  if (!is_array($data)) {
    throw new RuntimeException("The response from {$url} is not a JSON object.");
  }

  return $data;
}

/**
 * Lists the releases that are already on GitHub.
 *
 * @param string $repository
 *   The repository, such as Decipher/vale.
 *
 * @return array<string, bool>
 *   Whether each release is a draft, keyed by tag name.
 */
function mirror_fetch_github_releases(string $repository): array {
  $result = mirror_run([
    'gh', 'api', "repos/{$repository}/releases", '--paginate',
    '--jq', '.[] | {tag_name, draft}',
  ]);
  if ($result['code'] !== 0) {
    throw new RuntimeException("Could not list the GitHub releases: {$result['stderr']}");
  }

  $releases = [];
  foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
    if ($line === '') {
      continue;
    }
    try {
      $release = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (JsonException $e) {
      throw new RuntimeException('The GitHub release list is not valid JSON: ' . $e->getMessage(), $e->getCode(), $e);
    }
    if (!is_array($release) || !isset($release['tag_name']) || !is_string($release['tag_name'])) {
      throw new RuntimeException('A GitHub release has no tag name.');
    }
    $releases[$release['tag_name']] = !empty($release['draft']);
  }

  return $releases;
}

/**
 * Checks that a tag exists on GitHub.
 *
 * @param string $repository
 *   The repository, such as Decipher/vale.
 * @param string $tag
 *   The tag name.
 *
 * @return bool
 *   TRUE when the tag exists.
 */
function mirror_github_tag_exists(string $repository, string $tag): bool {
  // The singular ref endpoint matches the exact name. The plural one also
  // matches tags that start with it, so 1.0.0 would find 1.0.0-beta1.
  $result = mirror_run([
    'gh', 'api', "repos/{$repository}/git/ref/tags/{$tag}", '--jq', '.ref',
  ]);
  if ($result['code'] === 0) {
    return trim($result['stdout']) === "refs/tags/{$tag}";
  }
  if (str_contains($result['stderr'], 'HTTP 404')) {
    return FALSE;
  }
  throw new RuntimeException("Could not look up the tag {$tag}: {$result['stderr']}");
}

/**
 * Creates a GitHub release for a tag that is already on GitHub.
 *
 * @param string $repository
 *   The repository, such as Decipher/vale.
 * @param string $version
 *   The version, which is also the tag name.
 * @param string $notes
 *   The release notes.
 * @param bool $prerelease
 *   Whether to mark the release as a pre-release.
 * @param bool $latest
 *   Whether to mark the release as the latest.
 */
function mirror_create_github_release(string $repository, string $version, string $notes, bool $prerelease, bool $latest): void {
  $file = tempnam(sys_get_temp_dir(), 'vale-release-');
  if ($file === FALSE || file_put_contents($file, $notes) === FALSE) {
    throw new RuntimeException("Could not write the notes for {$version}.");
  }

  $command = [
    'gh', 'release', 'create', $version,
    '--repo', $repository,
    '--verify-tag',
    '--title', $version,
    '--notes-file', $file,
    '--latest=' . ($latest ? 'true' : 'false'),
  ];
  if ($prerelease) {
    $command[] = '--prerelease';
  }

  try {
    $result = mirror_run($command);
  }
  finally {
    unlink($file);
  }
  if ($result['code'] !== 0) {
    throw new RuntimeException("Could not create the GitHub release {$version}: {$result['stderr']}");
  }
}

/**
 * Finds the highest stable version.
 *
 * @param array<int, string> $versions
 *   The versions.
 *
 * @return string|null
 *   The highest stable version, or NULL when there is none.
 */
function mirror_highest_stable(array $versions): ?string {
  $highest = NULL;
  foreach ($versions as $version) {
    if (mirror_is_prerelease((string) $version)) {
      continue;
    }
    if ($highest === NULL || version_compare((string) $version, $highest, '>')) {
      $highest = (string) $version;
    }
  }
  return $highest;
}

/**
 * Compares two versions for sorting.
 *
 * @param string $a
 *   The first version.
 * @param string $b
 *   The second version.
 *
 * @return int
 *   Less than, equal to or greater than zero, as for usort().
 */
function mirror_compare_versions(string $a, string $b): int {
  return version_compare($a, $b);
}

/**
 * Checks whether a version is an alpha, beta or release candidate.
 *
 * @param string $version
 *   The version.
 *
 * @return bool
 *   TRUE for a pre-release.
 */
function mirror_is_prerelease(string $version): bool {
  return (bool) preg_match('/-(alpha|beta|rc)\d*$/i', $version);
}

/**
 * Runs a command without a shell.
 *
 * @param list<string> $command
 *   The command and its arguments.
 *
 * @return array{code: int, stdout: string, stderr: string}
 *   The exit code and output.
 */
function mirror_run(array $command): array {
  $pipes = [];
  $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) {
    throw new RuntimeException('Could not run ' . $command[0] . '.');
  }
  $stdout = (string) stream_get_contents($pipes[1]);
  $stderr = (string) stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code = proc_close($process);

  return ['code' => $code, 'stdout' => $stdout, 'stderr' => trim($stderr)];
}
