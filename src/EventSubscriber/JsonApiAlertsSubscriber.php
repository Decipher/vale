<?php

declare(strict_types=1);

namespace Drupal\vale\EventSubscriber;

use Drupal\vale\AlertCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the findings that did not stop a JSON:API write to its response.
 *
 * Errors already reach the client as core's 422 document. Warnings,
 * suggestions and errors on a draft in publish-only mode go to the
 * document's top-level meta, under "vale". A refused write gets the meta too,
 * so the client sees every finding of the attempt at once.
 */
class JsonApiAlertsSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a JsonApiAlertsSubscriber.
   *
   * @param \Drupal\vale\AlertCollector $collector
   *   The alert collector.
   */
  public function __construct(
    protected AlertCollector $collector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // JSON:API serializes its response at priority 128, so this runs after
    // it.
    return [KernelEvents::RESPONSE => ['onResponse', 100]];
  }

  /**
   * Adds the findings to a JSON:API write response.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    // JSON:API marks its routes with this default, which routing copies to
    // the request attributes.
    if (
      !$event->isMainRequest()
      || !in_array($request->getMethod(), ['POST', 'PATCH'], TRUE)
      || !$request->attributes->get('_is_jsonapi')
      || !$this->collector->hasLinted()
    ) {
      return;
    }
    $response = $event->getResponse();
    $document = json_decode((string) $response->getContent(), TRUE);
    if (!is_array($document)) {
      return;
    }

    $alerts = [];
    foreach ($this->collector->alerts() as $finding) {
      $alerts[] = [
        'entity_type' => $finding['entity_type'],
        'bundle' => $finding['bundle'],
        'id' => $finding['uuid'],
        'field' => $finding['field'],
        'delta' => $finding['delta'],
      ] + $finding['alert']->toArray();
    }
    $unchecked = $this->collector->unchecked();
    $document['meta']['vale'] = [
      'checked' => $unchecked === [],
      'alerts' => $alerts,
    ];
    if ($unchecked) {
      $document['meta']['vale']['unchecked'] = $unchecked;
    }
    $response->setContent((string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $this->collector->reset();
  }

}
