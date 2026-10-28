<?php

declare(strict_types=1);

namespace Drupal\vale\EventSubscriber;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\jsonapi\Exception\UnprocessableHttpEntityException;
use Drupal\vale\Plugin\Validation\Constraint\ValeTreeConstraint;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Gives Vale's entity-level JSON:API errors as plain text.
 *
 * Core's JSON:API decodes the HTML of a field violation's detail, but not of
 * an entity violation's. Under publish-only, Vale reports a refused publish
 * at /data, because JSON:API drops field violations for fields a PATCH does
 * not name. Without this, the detail carries "&#039;" for an apostrophe and
 * "<br>" between alerts. JSON:API does not allow other normalizers, so the
 * violations are rewritten before its exception subscriber normalizes them.
 * Only Vale's violations change.
 */
final class JsonApiErrorSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before JSON:API's exception subscriber builds the response.
    return [KernelEvents::EXCEPTION => ['onException', 100]];
  }

  /**
   * Turns Vale's entity violations into plain text.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if (!$exception instanceof UnprocessableHttpEntityException) {
      return;
    }
    $violations = $exception->getViolations();
    foreach ($violations as $offset => $violation) {
      if (!$violation instanceof ConstraintViolation || !$violation->getConstraint() instanceof ValeTreeConstraint) {
        continue;
      }
      $violations->set($offset, new ConstraintViolation(
        PlainTextOutput::renderFromHtml((string) $violation->getMessage()),
        $violation->getMessageTemplate(),
        $violation->getParameters(),
        $violation->getRoot(),
        $violation->getPropertyPath(),
        $violation->getInvalidValue(),
        $violation->getPlural(),
        $violation->getCode(),
        $violation->getConstraint(),
        $violation->getCause(),
      ));
    }
  }

}
