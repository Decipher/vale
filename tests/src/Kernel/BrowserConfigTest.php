<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\File\FileSystem;
use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Controller\BrowserConfigController;
use Drupal\vale\Hook\ValeFieldUiHooks;
use Drupal\vale\Hook\ValeRequirements;
use Drupal\vale\ValeEnvironment;
use Drupal\vale\ValePackages;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the routes that serve the site's Vale configuration to browsers.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class BrowserConfigTest extends KernelTestBase {

  use UserCreationTrait;
  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'language',
    'node',
    'paragraphs',
    'system',
    'text',
    'user',
    'vale',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpVale();
    mkdir($this->valeDirectory . '/tmp');
    $this->setSetting('file_temp_path', $this->valeDirectory . '/tmp');
    $this->container->set('file_system', new FileSystem($this->container->get('stream_wrapper_manager'), Settings::getInstance()));
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('user');
    $this->installConfig(['filter', 'language', 'node', 'vale']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->createValeContentModel();
    $_ENV['FAKE_VERSION'] = '3.27.0';

    // What a synced StylesPath holds, and what the browser must not get.
    $styles = $this->valeDirectory . '/styles';
    $files = [
      'config/vocabularies/Site/accept.txt' => "Drupal\n",
      'config/grammars/javascript.wasm' => 'wasm',
      'config/models/fr/model.json' => '{}',
      'config/models/de/model.json' => '{}',
      'config/dictionaries/fr.dic' => "1\nmot\n",
      'config/dictionaries/fr.aff' => "SET UTF-8\n",
      'config/dictionaries/de_DE.dic' => "1\nWort\n",
      'config/dictionaries/en_AU.dic' => "hello\n",
      '.git/config' => '[core]',
    ];
    foreach ($files as $file => $contents) {
      @mkdir(dirname($styles . '/' . $file), 0777, TRUE);
      file_put_contents($styles . '/' . $file, $contents);
    }
    // A link out of the styles is not followed.
    file_put_contents($this->valeDirectory . '/secret.txt', 'secret');
    symlink($this->valeDirectory . '/secret.txt', $styles . '/Demo/Secret.yml');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unset($_ENV['FAKE_VERSION']);
    parent::tearDown();
  }

  /**
   * Gives the controller.
   */
  protected function controller(): BrowserConfigController {
    return BrowserConfigController::create($this->container);
  }

  /**
   * Gives the names of the files in a zip, sorted.
   *
   * @param string $zip
   *   The zip's bytes.
   *
   * @return array<string, string>
   *   The contents of each file, keyed by name.
   */
  protected function unzip(string $zip): array {
    $file = $this->valeDirectory . '/tmp/test.zip';
    file_put_contents($file, $zip);
    $archive = new \ZipArchive();
    $this->assertTrue($archive->open($file));
    $files = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
      $name = (string) $archive->getNameIndex($i);
      $files[$name] = (string) $archive->getFromIndex($i);
    }
    $archive->close();
    ksort($files);
    return $files;
  }

  /**
   * Asserts that a call is refused as not found.
   *
   * @param callable $call
   *   The call.
   */
  protected function assertNotFound(callable $call): void {
    try {
      $call();
      $this->fail('The request was not refused.');
    }
    catch (NotFoundHttpException) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * Tests the configuration for each language.
   */
  public function testConfig(): void {
    $response = $this->controller()->config(new Request(), 'fr');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    $this->assertSame("StylesPath = /styles\nLang = fr\nMinAlertLevel = suggestion\n\n[*]\nBasedOnStyles = Demo\n\n[*.field_callout.md]\nBasedOnStyles = Demo\nDemo.Very = NO\n", $response->getContent());

    // English is the site's configuration, with only StylesPath changed. A
    // top-level Lang of the site's own is kept.
    file_put_contents($this->valeDirectory . '/.vale.ini', "StylesPath = styles\nLang = en_AU\n\n[*]\nBasedOnStyles = Demo\n");
    $this->assertSame("StylesPath = /styles\nLang = en_AU\n\n[*]\nBasedOnStyles = Demo\n", $this->controller()->config(new Request(), 'en')->getContent());
    $this->assertSame("StylesPath = /styles\nLang = en_AU\n\n[*]\nBasedOnStyles = Demo\n", $this->controller()->config(new Request(), 'und')->getContent());

    // Not a language of the site, or no configuration.
    $this->assertNotFound(fn (): Response => $this->controller()->config(new Request(), 'de'));
    $this->setSetting('vale_config', NULL);
    $this->assertNotFound(fn (): Response => $this->controller()->config(new Request(), 'en'));
  }

  /**
   * Tests the styles zip.
   */
  public function testStyles(): void {
    $response = $this->controller()->styles(new Request());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/zip', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('filename=vale-styles.zip', (string) $response->headers->get('Content-Disposition'));
    $files = $this->unzip((string) $response->getContent());
    // The zip holds what is in StylesPath, at its top, as a Vale package
    // does. Grammars, models, the dictionaries of other languages, hidden
    // files and links out of StylesPath are left out.
    $this->assertSame([
      'Demo/Delve.yml',
      'Demo/Really.yml',
      'Demo/Very.yml',
      'config/dictionaries/en_AU.dic',
      'config/vocabularies/Site/accept.txt',
    ], array_keys($files));
    $this->assertSame(file_get_contents($this->valeDirectory . '/styles/Demo/Very.yml'), $files['Demo/Very.yml']);

    // The zip is cached on the fingerprint the results use.
    $fingerprint = $this->container->get('vale.environment')->fingerprint();
    $this->assertNotFalse($this->container->get('cache.vale')->get('zip:styles:' . $fingerprint));
    $etag = (string) $response->headers->get('ETag');
    $this->assertNotSame('', $etag);
    $request = new Request();
    $request->headers->set('If-None-Match', $etag);
    $this->assertSame(304, $this->controller()->styles($request)->getStatusCode());

    // Asked for by the fingerprint, the zip is kept without asking again.
    // Any other "v" is checked again, as a zip without one is.
    $this->assertSame('max-age=0, must-revalidate, private', $response->headers->get('Cache-Control'));
    $kept = $this->controller()->styles(new Request(['v' => $fingerprint]));
    $this->assertSame('immutable, max-age=31536000, private', $kept->headers->get('Cache-Control'));
    $this->assertSame($etag, $kept->headers->get('ETag'));
    $this->assertSame('max-age=0, must-revalidate, private', $this->controller()->styles(new Request(['v' => 'old']))->headers->get('Cache-Control'));
    // The configuration is never kept, as more than the styles decides it.
    $this->assertSame('max-age=0, must-revalidate, private', $this->controller()->config(new Request(['v' => $fingerprint]), 'fr')->headers->get('Cache-Control'));

    // A changed style gives a new zip.
    file_put_contents($this->valeDirectory . '/styles/Demo/New.yml', "extends: existence\n");
    $this->container->get('vale.environment')->resetStylesPaths();
    $response = $this->controller()->styles($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayHasKey('Demo/New.yml', $this->unzip((string) $response->getContent()));
    $this->assertNotSame($etag, $response->headers->get('ETag'));

    // No Vale, no styles.
    $this->setSetting('vale_binary', NULL);
    $this->container->get('vale.environment')->resetStylesPaths();
    $this->assertNotFound(fn (): Response => $this->controller()->styles(new Request()));
  }

  /**
   * Tests the zip of one language's model and dictionary.
   */
  public function testModels(): void {
    $response = $this->controller()->models(new Request(), 'fr');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/zip', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('filename=vale-models-fr.zip', (string) $response->headers->get('Content-Disposition'));
    $this->assertSame([
      'config/dictionaries/fr.aff',
      'config/dictionaries/fr.dic',
      'config/models/fr/model.json',
    ], array_keys($this->unzip((string) $response->getContent())));
    $fingerprint = $this->container->get('vale.environment')->fingerprint();
    $this->assertNotFalse($this->container->get('cache.vale')->get('zip:models:fr:' . $fingerprint));

    $this->assertSame('immutable, max-age=31536000, private', $this->controller()->models(new Request(['v' => $fingerprint]), 'fr')->headers->get('Cache-Control'));
    // French is the only language of the site with a model or a dictionary.
    $this->assertSame(['fr'], $this->container->get('vale.packages')->modelLanguages());
    $this->assertNotFalse($this->container->get('cache.vale')->get('zip:models:bases:' . $fingerprint));
    $this->assertSame(['fr'], $this->container->get('vale.packages')->modelLanguages());

    // English has no model, German is not a language of the site, and a
    // langcode is never read as a path.
    foreach (['en', 'de', '..', 'fr/../de'] as $langcode) {
      $this->assertNotFound(fn (): Response => $this->controller()->models(new Request(), $langcode));
    }

    // A language with nothing to serve.
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->assertNotFound(fn (): Response => $this->controller()->models(new Request(), 'es'));
    $this->assertSame(['fr'], $this->container->get('vale.packages')->modelLanguages());

    // Vale before 3.27.0 reads every language as English.
    $_ENV['FAKE_VERSION'] = '3.26.0';
    $this->container->get('cache.vale')->deleteAll();
    $this->assertNotFound(fn (): Response => $this->controller()->models(new Request(), 'fr'));
    $this->assertSame([], $this->container->get('vale.packages')->modelLanguages());
  }

  /**
   * Tests that the routes need the check endpoint's permission.
   */
  public function testAccess(): void {
    $access = $this->container->get('access_manager');
    $routes = [
      ['vale.browser_config', ['langcode' => 'fr']],
      ['vale.browser_styles', []],
      ['vale.browser_models', ['langcode' => 'fr']],
    ];
    $allowed = $this->asAccount($this->createUser(['use vale check']));
    $refused = $this->asAccount($this->createUser(['access content']));
    foreach ($routes as [$route, $parameters]) {
      $this->assertTrue($access->checkNamedRoute($route, $parameters, $allowed), $route);
      $this->assertFalse($access->checkNamedRoute($route, $parameters, $refused), $route);
    }
  }

  /**
   * Gives a packer whose temporary file goes wrong.
   *
   * @param string $fault
   *   What goes wrong: "tempnam" gives no file, "realpath" gives no path,
   *   "open" gives a path with no file, and "close" lets nothing be written
   *   beside the file.
   */
  protected function faultyPackages(string $fault): ValePackages {
    $file_system = new class($this->container->get('stream_wrapper_manager'), Settings::getInstance()) extends FileSystem {

      /**
       * What goes wrong.
       */
      public string $fault = '';

      /**
       * {@inheritdoc}
       */
      public function tempnam($directory, $prefix) {
        return $this->fault === 'tempnam' ? FALSE : parent::tempnam($directory, $prefix);
      }

      /**
       * {@inheritdoc}
       */
      public function realpath($uri) {
        $path = parent::realpath($uri);
        // Only the packer's own temporary file goes wrong.
        if (!is_string($path) || !str_starts_with(basename($path), 'vale')) {
          return $path;
        }
        if ($this->fault === 'realpath') {
          return FALSE;
        }
        if ($this->fault === 'open') {
          return $path . '/missing';
        }
        if ($this->fault === 'close') {
          chmod(dirname($path), 0555);
        }
        return $path;
      }

      /**
       * {@inheritdoc}
       */
      public function unlink($uri, $context = NULL) {
        if ($this->fault === 'close') {
          chmod(dirname($uri), 0755);
        }
        return parent::unlink($uri, $context);
      }

    };
    $file_system->fault = $fault;
    return new ValePackages(
      $this->container->get('vale.environment'),
      $this->container->get('cache.vale'),
      $file_system,
      $this->container->get('datetime.time'),
      $this->container->get('language_manager'),
    );
  }

  /**
   * Tests each way the temporary file of a zip can go wrong.
   */
  public function testTemporaryFileFailure(): void {
    // A link to nothing is no file to pack.
    symlink($this->valeDirectory . '/styles/Demo/gone', $this->valeDirectory . '/styles/Demo/broken');
    $faults = [
      'tempnam' => 'no temporary file could be made.',
      'realpath' => 'no temporary file could be made.',
      'open' => '/missing could not be opened as a zip.',
    ];
    // Root writes to a directory with no write permission.
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
      $faults['close'] = ' could not be written.';
    }
    foreach ($faults as $fault => $reason) {
      // ZipArchive also warns when it cannot open or write a zip. The packer
      // throws for both, so the warnings are let go.
      set_error_handler(static fn (): bool => TRUE, E_WARNING);
      try {
        $this->faultyPackages($fault)->styles();
        $this->fail("A zip was packed with the $fault fault.");
      }
      catch (\RuntimeException $exception) {
        $this->assertStringStartsWith('The Vale styles zip could not be packed: ', $exception->getMessage(), $fault);
        $this->assertStringEndsWith($reason, $exception->getMessage(), $fault);
      }
      finally {
        restore_error_handler();
      }
      // The "open" fault hides the file from the packer, so it is left.
      if ($fault === 'open') {
        foreach (glob($this->valeDirectory . '/tmp/vale*') ?: [] as $left) {
          unlink($left);
        }
      }
      $this->assertSame([], glob($this->valeDirectory . '/tmp/vale*'), $fault);
    }
    $fingerprint = $this->container->get('vale.environment')->fingerprint();
    $this->assertFalse($this->container->get('cache.vale')->get('zip:styles:' . $fingerprint));

    // The paths are cached, so a directory may be gone on this server. Then
    // there is nothing to pack.
    rename($this->valeDirectory . '/styles', $this->valeDirectory . '/styles-gone');
    $environment = new ValeEnvironment(
      $this->container->get('cache.vale'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('file_url_generator'),
      $this->root,
      $this->container->get('file_system'),
      $this->container->get('language_manager'),
    );
    $packages = new ValePackages(
      $environment,
      $this->container->get('cache.vale'),
      $this->container->get('file_system'),
      $this->container->get('datetime.time'),
      $this->container->get('language_manager'),
    );
    $this->assertNull($packages->styles());
    rename($this->valeDirectory . '/styles-gone', $this->valeDirectory . '/styles');
  }

  /**
   * Tests that a zip that cannot be packed is neither cached nor served.
   */
  public function testPackFailure(): void {
    // Vale lists the file, but it cannot be read into the zip. The
    // fingerprint is taken first, as the file's contents are part of it.
    $fingerprint = $this->container->get('vale.environment')->fingerprint();
    // Root reads the file anyway, so the case only holds for an unprivileged
    // user.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
      $this->markTestSkipped('Root can read a file with no permissions.');
    }
    $file = $this->valeDirectory . '/styles/Demo/Very.yml';
    chmod($file, 0);
    try {
      $this->controller()->styles(new Request());
      $this->fail('A zip that could not be packed was served.');
    }
    catch (\RuntimeException $exception) {
      // The message names the zip and the file that failed.
      $this->assertSame('The Vale styles zip could not be packed: Demo/Very.yml could not be read.', $exception->getMessage());
    }
    finally {
      chmod($file, 0644);
    }
    $this->assertFalse($this->container->get('cache.vale')->get('zip:styles:' . $fingerprint));
    $this->assertSame([], glob($this->valeDirectory . '/tmp/vale*'));

    // Once the file can be read, the next request packs it.
    $files = $this->unzip((string) $this->controller()->styles(new Request())->getContent());
    $this->assertArrayHasKey('Demo/Very.yml', $files);

    // A models zip names itself, not the styles.
    $file = $this->valeDirectory . '/styles/config/models/fr/model.json';
    chmod($file, 0);
    try {
      $this->controller()->models(new Request(), 'fr');
      $this->fail('A zip that could not be packed was served.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('The Vale models for fr zip could not be packed: config/models/fr/model.json could not be read.', $exception->getMessage());
    }
    finally {
      chmod($file, 0644);
    }
  }

  /**
   * Renders a requirement's description.
   *
   * @param array<string, mixed> $requirement
   *   The requirement.
   *
   * @return string
   *   The description as HTML.
   */
  protected function description(array $requirement): string {
    $description = $requirement['description'];
    return is_array($description) ? (string) $this->container->get('renderer')->renderInIsolation($description) : (string) $description;
  }

  /**
   * Tests the download link on the status report and the field settings.
   */
  public function testDownloadLink(): void {
    $requirements = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeRequirements::class);
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeFieldUiHooks::class);
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);

    $this->setUpCurrentUser([], ['use vale check']);
    $requirement = $requirements->requirement();
    $this->assertStringContainsString('/vale/styles"', $this->description($requirement));
    $element = $hooks->settingsElement($field);
    $this->assertArrayHasKey('download', $element);
    $this->assertStringContainsString('/vale/styles', (string) $this->container->get('renderer')->renderInIsolation($element['download']));

    // The link depends on a permission, so both say so to the render cache.
    $this->assertIsArray($requirement['description']);
    $this->assertContains('user.permissions', $requirement['description']['#cache']['contexts'] ?? []);
    $this->assertContains('user.permissions', $element['#cache']['contexts'] ?? []);

    // A user who may not download the styles is not offered the link. The
    // permission is still a cache context, as the link would show for others.
    $this->setUpCurrentUser([], ['access content']);
    $requirement = $requirements->requirement();
    $this->assertStringNotContainsString('/vale/styles', $this->description($requirement));
    $this->assertIsArray($requirement['description']);
    $this->assertContains('user.permissions', $requirement['description']['#cache']['contexts'] ?? []);
    $element = $hooks->settingsElement($field);
    $this->assertArrayNotHasKey('download', $element);
    $this->assertContains('user.permissions', $element['#cache']['contexts'] ?? []);

    // With no styles, there is nothing to download, so neither links.
    $this->setUpCurrentUser([], ['use vale check']);
    rename($this->valeDirectory . '/styles', $this->valeDirectory . '/styles.old');
    $this->container->get('vale.environment')->resetStylesPaths();
    $this->assertNotFound(fn (): Response => $this->controller()->styles(new Request()));
    $this->assertStringNotContainsString('/vale/styles', $this->description($requirements->requirement()));
    $this->assertArrayNotHasKey('download', $hooks->settingsElement($field));
  }

}
