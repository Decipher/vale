<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\File\Exception\FileWriteException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
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

  /**
   * Tests each file system step that can fail.
   */
  public function testFileSystemFailures(): void {
    $archive = $this->archive();
    $expected = [
      'mkdir' => 'temporary directory',
      'move' => 'could not be written',
      'chmod' => 'could not be written',
    ];
    foreach ($expected as $step => $message) {
      $stack = HandlerStack::create(new MockHandler([new Response(200, [], $archive)]));
      $installer = new ValeInstaller(new Client(['handler' => $stack]), $this->failingFileSystem($step));
      $error = NULL;
      try {
        $installer->install($this->directory . '/bin-' . $step, '9.9.9', hash('sha256', $archive), 'Linux_64-bit');
      }
      catch (\RuntimeException $e) {
        $error = $e->getMessage();
      }
      $this->assertNotNull($error, "A failed $step was not reported.");
      $this->assertStringContainsString($message, $error);
      // Nothing is left half-installed.
      $this->assertFileDoesNotExist($this->directory . '/bin-' . $step . '/vale.new');
    }
  }

  /**
   * Makes a file system whose given step fails.
   *
   * @param string $step
   *   The step that fails: mkdir, move or chmod.
   *
   * @return \Drupal\Core\File\FileSystemInterface
   *   The file system.
   */
  protected function failingFileSystem(string $step): FileSystemInterface {
    // Drupal 10.3 also takes a logger. A third argument is ignored on 11.
    return new class($step, $this->container->get('stream_wrapper_manager'), Settings::getInstance(), $this->container->get('logger.channel.file')) extends FileSystem {

      /**
       * Constructs the file system.
       *
       * @param string $step
       *   The step that fails.
       * @param mixed ...$arguments
       *   The core file system's arguments.
       */
      public function __construct(private readonly string $step, mixed ...$arguments) {
        parent::__construct(...$arguments);
      }

      /**
       * {@inheritdoc}
       */
      public function mkdir($uri, $mode = NULL, $recursive = FALSE, $context = NULL) {
        return $this->step === 'mkdir' && str_contains($uri, 'vale-install-') ? FALSE : parent::mkdir($uri, $mode, $recursive, $context);
      }

      /**
       * {@inheritdoc}
       */
      public function move($source, $destination, $fileExists = FileExists::Rename) {
        if ($this->step === 'move') {
          throw new FileWriteException('Move failed.');
        }
        return parent::move($source, $destination, $fileExists);
      }

      /**
       * {@inheritdoc}
       */
      public function chmod($uri, $mode = NULL) {
        return $this->step === 'chmod' ? FALSE : parent::chmod($uri, $mode);
      }

    };
  }

}
