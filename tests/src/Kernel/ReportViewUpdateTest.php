<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update that replaces the content report view.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ReportViewUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'vale',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'views']);
    // Installing Views' config installs the current view. Each test starts
    // without it.
    $this->container->get('entity_type.manager')->getStorage('view')->load('vale_findings')?->delete();
    $this->container->get('module_handler')->loadInclude('vale', 'php', 'vale.post_update');
  }

  /**
   * Tests that a view unchanged since 1.0.1 is replaced.
   */
  public function testUnchangedViewIsReplaced(): void {
    $uuid = $this->installReleasedView();

    $message = (string) vale_post_update_report_table_fits();

    $config = $this->config('views.view.vale_findings');
    $this->assertSame($uuid, $config->get('uuid'));
    $options = $config->get('display.default.display_options');
    $this->assertSame('', $options['style']['options']['caption']);
    $this->assertArrayNotHasKey('root_bundle', $options['fields']);
    $this->assertArrayHasKey('root_label', $options['fields']);
    $this->assertStringContainsString('Updated', $message);
  }

  /**
   * Tests that a view saved by Drupal 10 still counts as unchanged.
   *
   * Drupal 10 saves the pager's default heading level into the view.
   */
  public function testDrupal10ViewIsReplaced(): void {
    $this->installReleasedView(static function (array &$data): void {
      $data['display']['default']['display_options']['pager']['options']['pagination_heading_level'] = 'h4';
    });

    $message = (string) vale_post_update_report_table_fits();

    $options = $this->config('views.view.vale_findings')->get('display.default.display_options');
    $this->assertArrayNotHasKey('root_bundle', $options['fields']);
    $this->assertStringContainsString('Updated', $message);
  }

  /**
   * Tests that a view changed on the site is kept, with a way to update it.
   */
  public function testChangedViewIsKept(): void {
    $this->installReleasedView(static function (array &$data): void {
      $data['display']['default']['display_options']['pager']['options']['items_per_page'] = 100;
    });

    $message = (string) vale_post_update_report_table_fits();

    $options = $this->config('views.view.vale_findings')->get('display.default.display_options');
    $this->assertSame('Prose linting', $options['style']['options']['caption']);
    $this->assertSame(100, $options['pager']['options']['items_per_page']);
    $this->assertArrayHasKey('root_bundle', $options['fields']);
    $this->assertStringContainsString('drush config:delete views.view.vale_findings', $message);
    $this->assertStringContainsString('installOptionalConfig', $message);
  }

  /**
   * Tests that a site without the view is left without it.
   */
  public function testMissingViewStaysMissing(): void {
    $this->assertTrue($this->config('views.view.vale_findings')->isNew());

    vale_post_update_report_table_fits();

    $this->assertTrue($this->config('views.view.vale_findings')->isNew());
  }

  /**
   * Installs the view that 1.0.0 and 1.0.1 shipped, as the installer does.
   *
   * @param callable|null $alter
   *   Changes the data before it is saved, as a site builder might.
   *
   * @return string
   *   The view's UUID.
   */
  protected function installReleasedView(?callable $alter = NULL): string {
    $data = Yaml::decode((string) file_get_contents(__DIR__ . '/../../fixtures/config/views.view.vale_findings.1.0.1.yml'));
    $data['langcode'] = 'en';
    if ($alter) {
      $alter($data);
    }
    $view = $this->container->get('entity_type.manager')->getStorage('view')->createFromStorageRecord($data);
    $view->save();
    return (string) $view->uuid();
  }

}
