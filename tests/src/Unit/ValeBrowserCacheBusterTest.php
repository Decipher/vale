<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\vale\ValeBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ?v= query on the scripts the worker loads itself.
 *
 * The kernel test runs from a checkout, which has no version. This pins the
 * release case too.
 *
 * @group vale
 * @coversDefaultClass \Drupal\vale\ValeBrowser
 */
#[Group('vale')]
class ValeBrowserCacheBusterTest extends UnitTestCase {

  /**
   * Gives each version, core's query string, and the query expected.
   *
   * @return array<string, array{mixed, string, string}>
   *   The cases.
   */
  public static function providerCacheBuster(): array {
    return [
      'release' => ['1.1.0', 't4k3n', '?v=1.1.0'],
      'release with build metadata' => ['1.1.0+2', 't4k3n', '?v=1.1.0%2B2'],
      'dev version' => ['1.1.x-dev', 't4k3n', '?v=t4k3n'],
      'empty version' => ['', 't4k3n', '?v=t4k3n'],
      'no version' => [NULL, 't4k3n', '?v=t4k3n'],
      'neither' => [NULL, '', '?v='],
    ];
  }

  /**
   * Tests the query for each version.
   *
   * @covers ::cacheBuster
   * @dataProvider providerCacheBuster
   */
  #[DataProvider('providerCacheBuster')]
  public function testCacheBuster(mixed $version, string $asset_query_string, string $expected): void {
    $this->assertSame($expected, ValeBrowser::cacheBuster($version, $asset_query_string));
  }

}
