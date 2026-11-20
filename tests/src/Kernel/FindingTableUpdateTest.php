<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\vale\Alert;
use Drupal\vale\Finding;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update that adds the component and prop columns.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
final class FindingTableUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'vale'];

  /**
   * Tests that the columns are added and the stored rows are kept.
   */
  public function testColumnsAreAdded(): void {
    $this->installSchema('vale', ['vale_finding']);
    $database = $this->container->get('database');
    $schema = $database->schema();
    // A table as 1.0 made it.
    $schema->dropField('vale_finding', 'component');
    $schema->dropField('vale_finding', 'prop');
    $database->insert('vale_finding')->fields([
      'root_type' => 'node',
      'root_id' => '1',
      'entity_type' => 'node',
      'entity_id' => '1',
      'field' => 'body',
      'rule' => 'Demo.Very',
      'level' => Alert::ERROR,
    ])->execute();

    $this->container->get('module_handler')->loadInclude('vale', 'install');
    vale_update_10101();
    $this->assertTrue($schema->fieldExists('vale_finding', 'component'));
    $this->assertTrue($schema->fieldExists('vale_finding', 'prop'));
    // Running it again changes nothing.
    vale_update_10101();

    $rows = array_map(static fn (object $row): array => (array) $row, $database->select('vale_finding', 'f')->fields('f', ['field', 'component', 'prop'])->execute()->fetchAll());
    $this->assertSame([['field' => 'body', 'component' => '', 'prop' => '']], $rows);

    // New findings store their component and prop.
    $alert = new Alert('Demo.Very', Alert::ERROR, "Avoid 'very'.", 1, 3, 6, 'very');
    $this->container->get('vale.finding_storage')->add([
      new Finding('canvas_page', '2', 'Page', 'canvas_page', 'components', 0, $alert, component: 'a6b5c8f0-4a3e-4c2b-9d4e-1f2a3b4c5d6e', prop: 'heading'),
    ]);
    $stored = $database->select('vale_finding', 'f')->fields('f', ['component', 'prop'])->condition('root_type', 'canvas_page')->execute()->fetchAssoc();
    $this->assertSame(['component' => 'a6b5c8f0-4a3e-4c2b-9d4e-1f2a3b4c5d6e', 'prop' => 'heading'], $stored);
  }

}
