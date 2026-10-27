<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Unit;

use Drupal\Core\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\jsonapi\Exception\UnprocessableHttpEntityException;
use Drupal\Tests\UnitTestCase;
use Drupal\vale\EventSubscriber\JsonApiErrorSubscriber;
use Drupal\vale\Plugin\Validation\Constraint\ValeTreeConstraint;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Tests that Vale's entity-level JSON:API errors become plain text.
 *
 * The publish-only flow over JSON:API is covered by JsonApiValidationTest.
 *
 * @group vale
 * @coversDefaultClass \Drupal\vale\EventSubscriber\JsonApiErrorSubscriber
 */
#[Group('vale')]
class JsonApiErrorSubscriberTest extends UnitTestCase {

  /**
   * Gives an exception event for a throwable.
   *
   * @param \Throwable $throwable
   *   The throwable.
   *
   * @return \Symfony\Component\HttpKernel\Event\ExceptionEvent
   *   The event.
   */
  private function event(\Throwable $throwable): ExceptionEvent {
    return new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $throwable);
  }

  /**
   * Tests that only Vale's violations are rewritten.
   */
  public function testPlainText(): void {
    $vale = new ConstraintViolation("Text: Avoid &#039;Very&#039;.<br>\nText: Avoid &#039;very&#039;.", NULL, [], NULL, '', NULL, NULL, NULL, new ValeTreeConstraint());
    $other = new ConstraintViolation('Other &#039;rule&#039;.', NULL, [], NULL, '', NULL, NULL, NULL, new NotNull());
    $violations = new EntityConstraintViolationList($this->createStub(FieldableEntityInterface::class), [$vale, $other]);
    $exception = new UnprocessableHttpEntityException();
    $exception->setViolations($violations);

    (new JsonApiErrorSubscriber())->onException($this->event($exception));
    $this->assertSame("Text: Avoid 'Very'.\nText: Avoid 'very'.", (string) $violations->get(0)->getMessage());
    $this->assertSame($vale->getConstraint(), $violations->get(0)->getConstraint());
    $this->assertSame('Other &#039;rule&#039;.', (string) $violations->get(1)->getMessage());
  }

  /**
   * Tests that other exceptions are left alone.
   */
  public function testOtherExceptions(): void {
    $event = $this->event(new \RuntimeException('Not JSON:API.'));
    (new JsonApiErrorSubscriber())->onException($event);
    $this->assertSame('Not JSON:API.', $event->getThrowable()->getMessage());
    // It runs before JSON:API's own exception subscriber.
    $this->assertSame([KernelEvents::EXCEPTION => ['onException', 100]], JsonApiErrorSubscriber::getSubscribedEvents());
  }

}
