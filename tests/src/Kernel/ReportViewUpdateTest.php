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
   * Tests that a view whose handlers were only reordered is kept.
   *
   * Views runs its handlers in the order they are listed.
   */
  public function testReorderedViewIsKept(): void {
    $this->installReleasedView(static function (array &$data): void {
      $fields = &$data['display']['default']['display_options']['fields'];
      $fields = array_reverse($fields, TRUE);
    });

    $message = (string) vale_post_update_report_table_fits();

    $this->assertArrayHasKey('root_bundle', $this->config('views.view.vale_findings')->get('display.default.display_options.fields'));
    $this->assertStringContainsString('has been changed on this site', $message);
  }

  /**
   * Tests that the replaced view keeps the site's language.
   */
  public function testLanguageIsKept(): void {
    $this->installReleasedView(static function (array &$data): void {
      $data['langcode'] = 'fr';
    });

    vale_post_update_report_table_fits();

    $config = $this->config('views.view.vale_findings');
    $this->assertArrayNotHasKey('root_bundle', $config->get('display.default.display_options.fields'));
    $this->assertSame('fr', $config->get('langcode'));
  }

  /**
   * Tests that a view changed on the site is kept, with a way to update it.
   */
  public function testChangedViewIsKept(): void {
    $this->installReleasedView(static function (array &$data): void {
      $data['display']['default']['display_options']['pager']['options']['items_per_page'] = 100;
    });

    $message = vale_post_update_report_table_fits();
    $this->assertNotNull($message);

    $options = $this->config('views.view.vale_findings')->get('display.default.display_options');
    $this->assertSame('Prose linting', $options['style']['options']['caption']);
    $this->assertSame(100, $options['pager']['options']['items_per_page']);
    $this->assertArrayHasKey('root_bundle', $options['fields']);

    // The two commands in the message install the new view, and nothing else.
    $arguments = $message->getArguments();
    $this->assertSame('drush config:delete views.view.vale_findings', $arguments['@delete']);
    $this->assertSame(1, preg_match('/^drush php:eval "(.+)"$/', $arguments['@install'], $matches));
    $this->config('views.view.vale_findings')->delete();
    $before = $this->container->get('config.storage')->listAll();
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged
    eval($matches[1]);
    $added = array_diff($this->container->get('config.storage')->listAll(), $before);
    $this->assertSame(['views.view.vale_findings'], array_values($added));
    $options = $this->config('views.view.vale_findings')->get('display.default.display_options');
    $this->assertArrayNotHasKey('root_bundle', $options['fields']);
    $this->assertSame(50, $options['pager']['options']['items_per_page']);
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
