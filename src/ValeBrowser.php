<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Asset\AssetQueryStringInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;

/**
 * Gives editor plugins what the vale/browser library needs.
 *
 * The library lints a value as the server would, so it needs each field's
 * settings and the site's formats. An editor plugin attaches them to the
 * element it enhances:
 *
 * @code
 * $element['#attached'] = BubbleableMetadata::mergeAttachments(
 *   $element['#attached'] ?? [],
 *   \Drupal::service(ValeBrowser::class)->attachments($field),
 * );
 * @endcode
 */
class ValeBrowser {

  /**
   * Constructs a ValeBrowser.
   *
   * @param \Drupal\vale\ValeEnvironment $environment
   *   The Vale environment.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\Core\Asset\AssetQueryStringInterface $assetQueryString
   *   The query string core adds to asset URLs.
   * @param \Drupal\vale\ValePackages $packages
   *   The packer for the styles.
   */
  public function __construct(
    protected ValeEnvironment $environment,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleExtensionList $moduleList,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected AssetQueryStringInterface $assetQueryString,
    protected ValePackages $packages,
  ) {}

  /**
   * Gives the attachments that let the library lint a field's values.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   *
   * @return array{library: string[], drupalSettings: array<string, mixed>}
   *   The attachments, for #attached. A field Vale does not check gets the
   *   library only.
   */
  public function attachments(FieldDefinitionInterface $field): array {
    $settings = $this->configFactory->get('vale.settings');
    $formats = $settings->get('formats');
    $vale = [
      'browser' => $this->urls(),
      'formats' => is_array($formats) ? $formats : [],
      'defaultExtension' => (string) $settings->get('default_extension'),
    ];
    $field_settings = FieldSettings::forField($field);
    if ($field_settings->enabled) {
      $entity_type = $field->getTargetEntityTypeId();
      $key = implode('.', [$entity_type, $field->getTargetBundle() ?? $entity_type, $field->getName()]);
      $vale['fields'][$key] = [
        'name' => $field_settings->name($field),
        'plain' => in_array($field->getType(), FieldLinter::PLAIN_TYPES, TRUE),
        'minLevel' => $field_settings->minLevel,
        'disabledRules' => $field_settings->disabledRules,
        'levelOverrides' => (object) $field_settings->levelOverrides,
      ];
    }
    return [
      'library' => ['vale/browser'],
      'drupalSettings' => ['vale' => $vale],
    ];
  }

  /**
   * Gives the ?v= query that versions the worker's own scripts.
   *
   * A release has a version. A checkout from git does not, or has a dev
   * version that stays the same across commits, so it gets the query string
   * core changes each time the caches are cleared.
   *
   * @param mixed $version
   *   The module's version from its info file, if it has one.
   * @param string $asset_query_string
   *   The query string core adds to asset URLs.
   *
   * @return string
   *   The query, such as "?v=1.1.0", or "?v=" when there is neither.
   */
  public static function cacheBuster(mixed $version, string $asset_query_string): string {
    if (!is_string($version) || $version === '' || str_ends_with($version, '-dev')) {
      $version = $asset_query_string;
    }
    return '?v=' . rawurlencode($version);
  }

  /**
   * Gives the URLs the library loads from.
   *
   * The worker loads its scripts itself, so they do not get the query
   * string core adds to a library's files. Each ends in ?v= instead, as the
   * browser build's URLs do, so a browser never runs an old script against
   * a new build.
   *
   * The styles and models zips are asked for with the styles' fingerprint,
   * so a browser keeps each until the styles change. The configuration is
   * not, as more than the styles decides it.
   *
   * @return array<string, string|list<string>|null>
   *   The URLs. The module and loader are NULL when the browser build is
   *   missing, and the library checks values on the server instead. With
   *   them are the languages that have a model, so the library asks for no
   *   other language's.
   */
  protected function urls(): array {
    $path = $this->moduleList->getPath('vale') . '/js/';
    $wasm = $this->environment->wasmUrls();
    $kept = ['query' => ['v' => $this->environment->fingerprint()]];
    $query = static::cacheBuster($this->moduleList->getExtensionInfo('vale')['version'] ?? NULL, $this->assetQueryString->get());
    return [
      'module' => $wasm['module'] ?? NULL,
      'loader' => $wasm['loader'] ?? NULL,
      'worker' => $this->fileUrlGenerator->generateString($path . 'vale-worker.js') . $query,
      'engine' => $this->fileUrlGenerator->generateString($path . 'vale-engine.js') . $query,
      'common' => $this->fileUrlGenerator->generateString($path . 'vale-common.js') . $query,
      'config' => Url::fromRoute('vale.browser_config', ['langcode' => 'LANGCODE'])->toString(),
      'styles' => Url::fromRoute('vale.browser_styles', [], $kept)->toString(),
      'models' => Url::fromRoute('vale.browser_models', ['langcode' => 'LANGCODE'], $kept)->toString(),
      'modelLanguages' => $this->packages->modelLanguages(),
      'check' => Url::fromRoute('vale.check')->toString(),
      'token' => Url::fromRoute('system.csrftoken')->toString(),
    ];
  }

}
