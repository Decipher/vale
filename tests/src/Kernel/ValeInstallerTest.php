<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\vale\ValeInstaller;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * Tests installing Vale from a release archive.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ValeInstallerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['vale'];

  /**
   * A scratch directory.
   */
  protected string $directory;

  /**
   * The requests the installer made.
   *
   * @var \ArrayObject<int, array<mixed>>
   */
  protected \ArrayObject $requests;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/vale-installer-' . bin2hex(random_bytes(6));
    mkdir($this->directory);
    $this->requests = new \ArrayObject();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->directory));
    parent::tearDown();
  }

  /**
   * Builds a release archive that holds a vale binary.
   *
   * @return string
   *   The archive's contents.
   */
  protected function archive(string $binary = "#!/bin/sh\necho 'vale version 9.9.9'\n", string $name = 'vale'): string {
    // A name of its own, since PharData keeps an archive it opened.
    $tar = $this->directory . '/build-' . bin2hex(random_bytes(4)) . '.tar';
    $phar = new \PharData($tar);
    $phar->addFromString($name, $binary);
    $phar->addFromString('README.md', 'Vale');
    $phar->compress(\Phar::GZ);
    $contents = (string) file_get_contents($tar . '.gz');
    unlink($tar);
    unlink($tar . '.gz');
    return $contents;
  }

  /**
   * Makes an installer whose downloads come from the given responses.
   */
  protected function installer(Response ...$responses): ValeInstaller {
    $stack = HandlerStack::create(new MockHandler(array_values($responses)));
    // The history middleware takes its container by reference. An
    // ArrayObject is shared through a local variable all the same.
    $history = $this->requests;
    $stack->push(Middleware::history($history));
    return new ValeInstaller(new Client(['handler' => $stack]), $this->container->get('file_system'));
  }

  /**
   * Tests installing a release whose checksum matches.
   */
  public function testInstall(): void {
    $archive = $this->archive();
    $binary = $this->installer(new Response(200, [], $archive))
      ->install($this->directory . '/bin', '9.9.9', hash('sha256', $archive), 'Linux_64-bit');

    $this->assertSame($this->directory . '/bin/vale', $binary);
    $this->assertTrue(is_executable($binary));
    $request = $this->requests[0]['request'] ?? NULL;
    $this->assertInstanceOf(RequestInterface::class, $request);
    $this->assertSame(
      'https://github.com/errata-ai/vale/releases/download/v9.9.9/vale_9.9.9_Linux_64-bit.tar.gz',
      (string) $request->getUri(),
    );
    exec(escapeshellarg($binary), $output);
    $this->assertSame(['vale version 9.9.9'], $output);
  }

  /**
   * Tests that a download with another checksum is refused.
   */
  public function testChecksumMismatch(): void {
    $installer = $this->installer(new Response(200, [], $this->archive()));
    try {
      $installer->install($this->directory . '/bin', '9.9.9', str_repeat('0', 64), 'Linux_64-bit');
      $this->fail('A changed archive was installed.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('It was not installed.', $e->getMessage());
    }
    $this->assertFileDoesNotExist($this->directory . '/bin/vale');
  }

  /**
   * Tests that the pinned version is checked against the pinned checksum.
   */
  public function testPinnedChecksum(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('a6f71a75a12fe689345b754f2412b90367fe33648abb7d200fa19eaadc2dbf6d');
    $this->installer(new Response(200, [], $this->archive()))->install($this->directory . '/bin', NULL, NULL, 'Linux_64-bit');
  }

  /**
   * Tests that another version needs its checksum.
   */
  public function testVersionNeedsChecksum(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->installer()->install($this->directory . '/bin', '9.9.9', NULL, 'Linux_64-bit');
  }

  /**
   * Tests an archive without a binary, and a failed download.
   */
  public function testBadDownloads(): void {
    $archive = $this->archive(name: 'other');
    try {
      $this->installer(new Response(200, [], $archive))->install($this->directory . '/bin', '9.9.9', hash('sha256', $archive), 'Linux_64-bit');
      $this->fail('An archive without a binary was installed.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('has no vale binary', $e->getMessage());
    }

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('could not be downloaded');
    $this->installer(new Response(404))->install($this->directory . '/bin', '9.9.9', str_repeat('0', 64), 'Linux_64-bit');
  }

  /**
   * Tests mapping a machine to a release platform.
   */
  public function testPlatform(): void {
    $installer = $this->installer();
    $this->assertSame('Linux_64-bit', $installer->platform('Linux', 'x86_64'));
    $this->assertSame('Linux_arm64', $installer->platform('Linux', 'aarch64'));
    $this->assertSame('macOS_arm64', $installer->platform('Darwin', 'arm64'));
    $this->expectException(\RuntimeException::class);
    $installer->platform('Windows NT', 'AMD64');
  }

  /**
   * Tests a machine Vale publishes no release for.
   */
  public function testUnsupportedMachine(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Vale has no release for Linux on mips.');
    $this->installer()->platform('Linux', 'mips');
  }

  /**
   * Tests a checksum that is not a SHA-256.
   */
  public function testInvalidChecksum(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('No valid SHA-256 for Linux_64-bit.');
    $this->installer()->install($this->directory . '/bin', '9.9.9', 'not-a-checksum', 'Linux_64-bit');
  }

  /**
   * Tests a target that cannot be created or written.
   */
  public function testTargetNotWritable(): void {
    $archive = $this->archive();
    // A file where the directory should be.
    file_put_contents($this->directory . '/blocked', '');
    $this->assertInstallRefused($archive, $this->directory . '/blocked/bin');

    // A directory that cannot be written. Root writes to it anyway, so the
    // case only holds for an unprivileged user.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
      return;
    }
    mkdir($this->directory . '/readonly', 0555);
    try {
      $this->assertInstallRefused($archive, $this->directory . '/readonly');
    }
    finally {
      chmod($this->directory . '/readonly', 0755);
    }
  }

  /**
   * Asserts that an install into a directory is refused as not writable.
   *
   * @param string $archive
   *   The release archive.
   * @param string $directory
   *   The target directory.
   */
  protected function assertInstallRefused(string $archive, string $directory): void {
    $error = NULL;
    try {
      $this->installer(new Response(200, [], $archive))->install($directory, '9.9.9', hash('sha256', $archive), 'Linux_64-bit');
    }
    catch (\RuntimeException $e) {
      $error = $e->getMessage();
    }
    $this->assertNotNull($error, 'Vale was installed into ' . $directory . '.');
    $this->assertStringContainsString('cannot be written', $error);
  }

  /**
   * Tests that installing again replaces the binary.
   */
  public function testReplace(): void {
    $archive = $this->archive("#!/bin/sh\necho 'vale version 9.9.8'\n");
    $this->installer(new Response(200, [], $archive))->install($this->directory . '/bin', '9.9.8', hash('sha256', $archive), 'Linux_64-bit');
    $archive = $this->archive();
    $binary = $this->installer(new Response(200, [], $archive))->install($this->directory . '/bin', '9.9.9', hash('sha256', $archive), 'Linux_64-bit');
    exec(escapeshellarg($binary), $output);
    $this->assertSame(['vale version 9.9.9'], $output);
    $this->assertSame(['vale'], array_values(array_diff(scandir($this->directory . '/bin') ?: [], ['.', '..'])));
  }

}
