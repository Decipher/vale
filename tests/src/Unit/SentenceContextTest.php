<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\vale\Alert;
use Drupal\vale\SentenceContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests finding the sentence around an alert.
 *
 * @group vale
 * @coversDefaultClass \Drupal\vale\SentenceContext
 */
#[Group('vale')]
class SentenceContextTest extends UnitTestCase {

  /**
   * Gives texts, alert positions and the sentence expected.
   *
   * @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: string}>
   *   Text, line, start and end column, and the sentence.
   */
  public static function sentences(): array {
    return [
      'middle sentence' => ['One. Two very good. Three.', 1, 10, 13, 'Two very good.'],
      'at the start of the line' => ['Very good. Next.', 1, 1, 4, 'Very good.'],
      'no closing punctuation' => ['Fine. It is very good', 1, 13, 16, 'It is very good'],
      'second line only' => ["First line.\nIt is very good! Next?", 2, 7, 10, 'It is very good!'],
      'multibyte columns' => ['Café. Naïve and very.', 1, 17, 20, 'Naïve and very.'],
      'markup removed' => ['<p>It is <em>very</em> good.</p>', 1, 14, 17, 'It is very good.'],
      'a dot inside a word' => ['See vale.sh for very good docs.', 1, 17, 20, 'See vale.sh for very good docs.'],
      'line past the end' => ['Only one line.', 3, 1, 4, ''],
    ];
  }

  /**
   * Tests the sentence around a span.
   *
   * @dataProvider sentences
   */
  #[DataProvider('sentences')]
  public function testAround(string $text, int $line, int $start, int $end, string $expected): void {
    $alert = new Alert('Demo.Very', Alert::ERROR, 'Avoid it.', $line, $start, $end);
    $this->assertSame($expected, SentenceContext::around($text, $alert));
  }

}
