<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what an author sees on the entity form.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FormMessagesTest extends BrowserTestBase {

  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_reference_revisions', 'node', 'paragraphs', 'text', 'vale'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createValeContentModel();
    $this->showValeFieldsOnForm();
    $this->drupalLogin($this->asAccount($this->drupalCreateUser(['create page content', 'edit own page content', 'access content'])));
    $this->setUpVale();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
  }

  /**
   * Tests that an error is shown on its field and stops the save.
   */
  public function testError(): void {
    $this->drupalGet('node/add/page');
    $this->submitForm([
      'title[0][value]' => 'A page',
      'field_text[0][value]' => "This is very good.\nVery much so.",
      'field_code[0][value]' => 'Very, very code.',
    ], 'Save');
    $this->assertSession()->pageTextContains('Demo.Very: Avoid \'very\'. (line 1: "very")');
    // Both findings show, not only the first.
    $this->assertSession()->pageTextContains('Demo.Very: Avoid \'Very\'. (line 2: "Very")');
    $this->assertSession()->elementExists('css', 'textarea[name="field_text[0][value]"].error');
    $this->assertSession()->elementNotExists('css', 'textarea[name="field_code[0][value]"].error');
    $this->assertSession()->pageTextNotContains('A page has been created.');
  }

  /**
   * Tests that warnings are shown and the content saves.
   */
  public function testWarnings(): void {
    $this->drupalGet('node/add/page');
    $this->submitForm([
      'title[0][value]' => 'A page',
      'field_text[0][value]' => 'We delve.',
    ], 'Save');
    $this->assertSession()->pageTextContains('Page A page has been created.');
    $this->assertSession()->pageTextContains('A page, Text: Demo.Delve: \'delve\' is an AI tell. (line 1: "delve")');
  }

  /**
   * Tests that an author is told when the content was not checked.
   */
  public function testUnavailable(): void {
    $this->setValeSetting('vale_binary', NULL);
    $this->drupalGet('node/add/page');
    $this->submitForm([
      'title[0][value]' => 'A page',
      'field_text[0][value]' => 'This is very good.',
    ], 'Save');
    $this->assertSession()->pageTextContains('Page A page has been created.');
    $this->assertSession()->pageTextContains('Prose not checked:');
  }

  /**
   * Tests adding a paragraph, which rebuilds the form from the form cache.
   *
   * The form cache serializes the form, so every callback the module adds
   * must be serializable.
   */
  public function testAddParagraph(): void {
    $this->drupalGet('node/add/page');
    $this->submitForm([], 'field_content_docs_text_add_more');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'title[0][value]' => 'A page',
      'field_content[0][subform][field_text][0][value]' => 'This is very good.',
    ], 'Save');
    $this->assertSession()->pageTextContains('Demo.Very');
    $this->assertSession()->pageTextNotContains('Page A page has been created.');

    $this->submitForm(['field_content[0][subform][field_text][0][value]' => 'We delve.'], 'Save');
    $this->assertSession()->pageTextContains('Page A page has been created.');
    $this->assertSession()->pageTextContains('Demo.Delve');
  }

}
