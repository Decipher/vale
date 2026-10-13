<?php

declare(strict_types=1);

namespace Drupal\vale\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\vale\FieldLinter;
use Drupal\vale\SentenceContext;
use Drupal\vale\ValeEnvironment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gives the findings in a piece of text, as validation would see them.
 *
 * Live checks while editing and the preview mode are built on this. The
 * request names a field and carries text. It never carries Vale
 * configuration: the field's settings and the codebase's .vale.ini decide
 * what is checked.
 */
final readonly class CheckController implements ContainerInjectionInterface {

  /**
   * The keys a request may hold.
   */
  private const array KEYS = ['entity_type', 'bundle', 'field', 'format', 'text'];

  /**
   * The longest text that is checked, in characters.
   */
  private const int MAX_LENGTH = 100000;

  /**
   * Constructs a CheckController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\vale\FieldLinter $fieldLinter
   *   The field linter.
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private EntityFieldManagerInterface $entityFieldManager,
    private FieldLinter $fieldLinter,
    private ValeEnvironment $valeEnvironment,
    private FloodInterface $flood,
    private ConfigFactoryInterface $configFactory,
    private AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('vale.field_linter'),
      $container->get('vale.environment'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  /**
   * Checks the text in a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose JSON body names the entity type, bundle and field,
   *   and holds the text and its format.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The findings, or an error.
   */
  public function check(Request $request): JsonResponse {
    $settings = $this->configFactory->get('vale.settings');
    $limit = (int) ($settings->get('check_limit') ?: 120);
    $window = (int) ($settings->get('check_window') ?: 60);
    $identifier = 'user:' . $this->account->id();
    if (!$this->flood->isAllowed('vale.check', $limit, $window, $identifier)) {
      return new JsonResponse(['message' => 'Too many checks. Try again shortly.'], 429, ['Retry-After' => (string) $window]);
    }
    $this->flood->register('vale.check', $window, $identifier);

    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return $this->error('The request body must be a JSON object.', 400);
    }
    if ($unknown = array_diff(array_keys($body), self::KEYS)) {
      return $this->error(sprintf('Unknown keys: %s. Only %s are accepted.', implode(', ', $unknown), implode(', ', self::KEYS)), 400);
    }
    foreach (['entity_type', 'bundle', 'field', 'text'] as $key) {
      if (!is_string($body[$key] ?? NULL)) {
        return $this->error(sprintf('"%s" must be a string.', $key), 400);
      }
    }
    $format = $body['format'] ?? NULL;
    if ($format !== NULL && !is_string($format)) {
      return $this->error('"format" must be a string or null.', 400);
    }
    if (mb_strlen($body['text']) > self::MAX_LENGTH) {
      return $this->error(sprintf('"text" is longer than %d characters.', self::MAX_LENGTH), 400);
    }

    $entity_type = $this->entityTypeManager->getDefinition($body['entity_type'], FALSE);
    if (!$entity_type || !$entity_type->entityClassImplements(FieldableEntityInterface::class)
      || !isset($this->entityTypeBundleInfo->getBundleInfo($body['entity_type'])[$body['bundle']])) {
      return $this->error('Unknown entity type or bundle.', 400);
    }

    // The editor must be able to make this content and edit this field, so
    // the endpoint gives no one a linter for content they cannot write.
    $access = $this->entityTypeManager->getAccessControlHandler($body['entity_type']);
    $field = $this->entityFieldManager->getFieldDefinitions($body['entity_type'], $body['bundle'])[$body['field']] ?? NULL;
    if (!$access->createAccess($body['bundle'], $this->account) || ($field && !$access->fieldAccess('edit', $field, $this->account))) {
      return $this->error('You may not check text for this bundle.', 403);
    }
    if (!$field || !$this->fieldLinter->isEnabled($field)) {
      return $this->error('This field is not checked by Vale.', 400);
    }

    $result = $this->fieldLinter->lintText($field, $body['text'], $format);
    $rules = $result->alerts ? $this->valeEnvironment->rules() : [];
    $alerts = [];
    foreach ($result->alerts as $alert) {
      $alerts[] = [
        'description' => $alert->description !== '' ? $alert->description : ($rules[$alert->rule]['description'] ?? ''),
        'link' => $alert->link !== '' ? $alert->link : ($rules[$alert->rule]['link'] ?? ''),
        'sentence' => SentenceContext::around($body['text'], $alert),
      ] + $alert->toArray();
    }

    // An error-level alert stops a save, or in publish-only mode only the
    // save that publishes.
    $response = [
      'checked' => $result->isChecked(),
      'publish_only' => (bool) $settings->get('publish_only'),
      'alerts' => $alerts,
    ];
    if (!$result->isChecked()) {
      $response['reason'] = $result->reason;
    }
    return new JsonResponse($response);
  }

  /**
   * Makes an error response.
   *
   * @param string $message
   *   The message.
   * @param int $status
   *   The status code.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  private function error(string $message, int $status): JsonResponse {
    return new JsonResponse(['message' => $message], $status);
  }

}
