<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\EventSubscriber\JsonApiAlertsSubscriber;
use Drupal\vale\Hook\ValeFormHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests how collected findings reach a form user and a JSON:API client.
 *
 * FormMessagesTest and JsonApiValidationTest cover these over HTTP.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class AlertDisplayTest extends KernelTestBase {

  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'node',
    'paragraphs',
    'system',
    'text',
    'user',
    'vale',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('user');
    $this->installConfig(['filter', 'node', 'vale']);
    $this->setUpVale();
    $this->createValeContentModel();
  }

  /**
   * Collects findings as validation would.
   */
  protected function collect(): void {
    $collector = $this->container->get('vale.alert_collector');
    $page = Node::create(['type' => 'page', 'title' => 'Guide']);
    $collector->add($page, 'field_text', 0, new Alert('Demo.Delve', Alert::WARNING, "'delve' is an AI tell.", 1, 4, 8, 'delve'));
    $collector->add($page, 'field_text', 0, new Alert('Demo.Very', Alert::ERROR, "Avoid 'very'.", 2, 1, 4, 'Very'));
    // The same finding twice is listed once.
    $collector->add($page, 'field_text', 0, new Alert('Demo.Very', Alert::ERROR, "Avoid 'very'.", 2, 1, 4, 'Very'));
    $collector->addUnchecked('Vale ran out of time.');
  }

  /**
   * Tests the messages a form user sees.
   */
  public function testFormMessages(): void {
    $this->collect();
    $form = [];
    $this->container->get('class_resolver')->getInstanceFromDefinition(ValeFormHooks::class)->showAlerts($form, new FormState());

    $warnings = array_map(strval(...), $this->container->get('messenger')->messagesByType('warning'));
    $this->assertSame([
      'Guide, Text: Demo.Delve: &#039;delve&#039; is an AI tell. (line 1: "delve")',
      'Guide, Text: Demo.Very: Avoid &#039;very&#039;. (line 2: "Very"). Blocks publishing.',
      'Prose not checked: Vale ran out of time.',
    ], $warnings);
    $this->assertSame([], $this->container->get('vale.alert_collector')->alerts());
  }

  /**
   * Tests the meta a JSON:API client gets.
   */
  public function testJsonApiMeta(): void {
    $this->collect();
    $subscriber = new JsonApiAlertsSubscriber($this->container->get('vale.alert_collector'));

    // Not a JSON:API write: left alone.
    $request = Request::create('/jsonapi/node/page', 'GET');
    $request->attributes->set('_is_jsonapi', TRUE);
    $event = new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new Response('{"data":[]}'));
    $subscriber->onResponse($event);
    $this->assertSame('{"data":[]}', $event->getResponse()->getContent());

    // A refused write gets the meta too.
    $request = Request::create('/jsonapi/node/page', 'POST');
    $request->attributes->set('_is_jsonapi', TRUE);
    $event = new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new Response('{"errors":[]}', 422));
    $subscriber->onResponse($event);
    $document = json_decode((string) $event->getResponse()->getContent(), TRUE);
    $this->assertFalse($document['meta']['vale']['checked']);
    $this->assertSame(['Vale ran out of time.'], $document['meta']['vale']['unchecked']);
    $this->assertSame(['Demo.Delve', 'Demo.Very'], array_column($document['meta']['vale']['alerts'], 'rule'));
    $this->assertSame('field_text', $document['meta']['vale']['alerts'][0]['field']);

    // A response that is not JSON is left alone.
    $this->collect();
    $event = new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new Response('not json'));
    $subscriber->onResponse($event);
    $this->assertSame('not json', $event->getResponse()->getContent());
  }

}
