<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\FunctionalJavascript;

use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the findings an author sees while editing.
 *
 * The test site has no browser build, so the browser checks each value with
 * POST /vale/check, which runs the fake Vale.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class EditingFindingsTest extends ValeFunctionalJavascriptTestBase {

  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ckeditor5', 'editor', 'filter', 'node', 'text', 'vale'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FilterFormat::create(['format' => 'rich', 'name' => 'Rich', 'filters' => []])->save();
    Editor::create([
      'editor' => 'ckeditor5',
      'format' => 'rich',
      'image_upload' => ['status' => FALSE],
      'settings' => ['toolbar' => ['items' => ['bold', 'valeFindings']], 'plugins' => []],
    ])->save();
    $this->drupalCreateContentType(['type' => 'page']);
    $fields = [
      'field_text' => ['text_long', 'text_textarea', ['plain_text']],
      'field_rich' => ['text_long', 'text_textarea', ['rich']],
      'field_title_text' => ['string', 'string_textfield', NULL],
    ];
    $display = $this->container->get('entity_display.repository')->getFormDisplay('node', 'page');
    foreach ($fields as $name => [$type, $widget, $formats]) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
      $field = FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'page', 'label' => $name]);
      if ($formats) {
        $field->setSetting('allowed_formats', $formats);
      }
      $field->setThirdPartySetting('vale', 'enabled', TRUE)->save();
      $display->setComponent($name, ['type' => $widget]);
    }
    $display->save();
    $this->drupalLogin($this->asAccount($this->drupalCreateUser([
      'create page content',
      'use text format rich',
      'use vale check',
    ])));
    $this->setUpVale();
    $this->setValeSetting('vale_wasm', 'public://missing');
  }

  /**
   * Tests the list under a text area and a text field.
   */
  public function testFindingsList(): void {
    $this->drupalGet('node/add/page');
    // Drupal 10 types assertSession() as the plain WebAssert.
    /** @var \Drupal\FunctionalJavascriptTests\WebDriverWebAssert $session */
    $session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $list = '.form-item-field-text-0-value + .vale-findings';
    $open = "document.querySelector('$list details').open";

    // An error opens the list by itself. Its row has core's class for an
    // error, and the level as a word.
    $page->fillField('field_text[0][value]', 'This is very good.');
    $row = $session->waitForElementVisible('css', $list . ' tr.vale-findings__row--error.color-error');
    $this->assertNotNull($row, 'The finding is listed under the text area.');
    $this->assertStringContainsString('Demo.Very', $row->getText());
    $this->assertStringContainsString('Error', $row->getText());
    $session->elementTextContains('css', $list . ' .vale-findings__summary', 'Vale: 1 finding');
    $session->elementTextContains('css', $list . ' .vale-findings__summary', '1 error');
    $this->assertTrue($this->getSession()->evaluateScript($open));
    // The table scrolls in a region the keyboard reaches, named for the
    // field.
    $region = $session->elementExists('css', $list . ' .vale-findings__region[role="region"][tabindex="0"]');
    $this->assertSame('Vale findings for field_text', $region->getAttribute('aria-label'));

    // Selecting the finding selects its text in the field. The button shows
    // the text, and its name says what it does.
    $button = $row->find('css', '.vale-findings__select');
    $this->assertNotNull($button);
    $this->assertSame(
      'Select this text in the field: very',
      $this->getSession()->evaluateScript("document.querySelector('$list .vale-findings__select').textContent"),
    );
    $button->click();
    $this->assertSame(
      ['field_text[0][value]', 8, 12],
      $this->getSession()->evaluateScript('[document.activeElement.name, document.activeElement.selectionStart, document.activeElement.selectionEnd]'),
    );

    // Typing before a finding moves it with its text, before the next check.
    $this->getSession()->executeScript(<<<'JS'
      const field = document.querySelector('[name="field_text[0][value]"]');
      field.value = `Yes. ${field.value}`;
      field.dispatchEvent(new Event('input'));
      field.closest('.form-item').nextElementSibling.querySelector('.vale-findings__select').click();
      JS);
    $this->assertSame(
      [13, 17],
      $this->getSession()->evaluateScript('[document.activeElement.selectionStart, document.activeElement.selectionEnd]'),
    );

    // A check that fails keeps the findings, and says the text was not
    // checked. The fake Vale fails on FAKE_CRASH. Nothing in the list is a
    // live region: a screen reader is told through Drupal.announce().
    $this->getSession()->executeScript(<<<'JS'
      const field = document.querySelector('[name="field_text[0][value]"]');
      field.value = `${field.value} FAKE_CRASH`;
      field.dispatchEvent(new Event('input'));
      JS);
    $this->assertTrue($session->waitForText('Vale could not check this text. It is still checked when you save.'));
    $session->elementTextContains('css', $list . ' .vale-findings__row--error', 'Demo.Very');
    $session->elementNotExists('css', $list . ' [aria-live]');
    $this->assertTrue($session->waitForText('Vale could not check the text in field_text.'));

    // The author closes the list. A later check that finds an error leaves
    // it closed, as they chose.
    $session->elementExists('css', $list . ' .vale-findings__summary')->click();
    $this->assertFalse($this->getSession()->evaluateScript($open));
    $page->fillField('field_text[0][value]', 'This is very good, and very clear.');
    $this->assertTrue($session->waitForText('Vale: 2 findings'));
    $this->assertFalse($this->getSession()->evaluateScript($open));
    $this->assertTrue($session->waitForText('Vale: 2 findings in field_text'));

    // Fixing the text leaves one line, and no list.
    $page->fillField('field_text[0][value]', 'This is good.');
    $this->assertTrue($session->waitForText('Vale: no findings.'));
    $session->elementNotExists('css', $list . ' .vale-findings__row');
    $this->assertFalse($session->elementExists('css', $list . ' details')->isVisible());

    // A plain text field gets a list too. A warning does not open it.
    $title = '.form-item-field-title-text-0-value + .vale-findings';
    $page->fillField('field_title_text[0][value]', 'Delve into it.');
    $row = $session->waitForElement('css', $title . ' tr.vale-findings__row--warning.color-warning');
    $this->assertNotNull($row);
    $this->assertFalse($row->isVisible());
    $session->elementTextContains('css', $title . ' .vale-findings__summary', '1 warning');
    $this->assertFalse($this->getSession()->evaluateScript("document.querySelector('$title details').open"));
    // The author opens it, and the errors come before the warnings.
    $session->elementExists('css', $title . ' .vale-findings__summary')->click();
    $this->assertTrue($row->isVisible());
    $this->assertStringContainsString('Demo.Delve', $row->getText());
    $page->fillField('field_title_text[0][value]', 'Delve into it, very much.');
    $this->assertNotNull($session->waitForElementVisible('css', $title . ' .vale-findings__row--error'));
    $this->assertSame(
      ['Error', 'Warning'],
      $this->getSession()->evaluateScript("Array.from(document.querySelectorAll('$title .vale-findings__level')).map((level) => level.textContent)"),
    );
  }

  /**
   * Runs a script against the field_rich editor.
   *
   * @param string $script
   *   The script, with the editor in "editor".
   *
   * @return mixed
   *   What the script returns.
   */
  protected function editor(string $script): mixed {
    $find = "const editor = Drupal.CKEditor5Instances.get(document.querySelector('[name=\"field_rich[0][value]\"]').dataset.ckeditor5Id);";
    return $this->getSession()->evaluateScript("(() => { $find $script })()");
  }

  /**
   * Tests the marks in CKEditor 5, their card and the toolbar button.
   */
  public function testCkeditorMarks(): void {
    $this->drupalGet('node/add/page');
    // Drupal 10 types assertSession() as the plain WebAssert.
    /** @var \Drupal\FunctionalJavascriptTests\WebDriverWebAssert $session */
    $session = $this->assertSession();
    $this->assertNotNull($session->waitForElement('css', '.form-item-field-rich-0-value .ck-editor__editable'));
    $this->editor("editor.setData('<p>This is <strong>very</strong> good, really.</p>');");

    $mark = $session->waitForElementVisible('css', '.ck-editor__editable .vale-mark--error');
    $this->assertNotNull($mark, 'The finding is marked in the text.');
    $this->assertSame('very', $mark->getText());
    $suggestion = $session->waitForElementVisible('css', '.ck-editor__editable .vale-mark--suggestion');
    $this->assertNotNull($suggestion);
    $this->assertSame('really', $suggestion->getText());
    // The marks are not part of the value.
    $this->assertSame('<p>This is <strong>very</strong> good, really.</p>', $this->editor('return editor.getData();'));
    // The editor's field shows no list, nor any line under it. The list
    // comes back when the text format is switched to one with no editor.
    $session->elementNotExists('css', '.form-item-field-rich-0-value + .vale-findings .vale-findings__row');
    $this->assertFalse($session->elementExists('css', '.form-item-field-rich-0-value + .vale-findings')->isVisible());
    // The button no longer says the text is being checked.
    $session->elementNotExists('css', '.vale-findings-button--checking');
    $session->elementExists('css', '.ck-button.vale-findings-button');

    // Hovering a mark shows the rule, the message and the link.
    $mark->mouseOver();
    $card = $session->waitForElementVisible('css', '.vale-card');
    $this->assertNotNull($card);
    $this->assertStringContainsString('Demo.Very', $card->getText());
    $this->assertStringContainsStringIgnoringCase('Error', $card->getText());
    $session->elementExists('css', '.vale-card a[href]');

    // Deleting all the text takes the marks and the card with it, and
    // leaves no list under the editor.
    $this->editor("editor.model.change((writer) => { const range = writer.createRangeIn(editor.model.document.getRoot()); editor.model.deleteContent(writer.createSelection(range)); });");
    $this->assertTrue($session->waitForElementRemoved('css', '.ck-editor__editable .vale-mark'));
    $this->assertFalse($session->elementExists('css', '.vale-card')->isVisible());
    $this->assertFalse($session->elementExists('css', '.form-item-field-rich-0-value + .vale-findings')->isVisible());
    $this->editor("editor.setData('<p>This is <strong>very</strong> good, really.</p>');");
    $mark = $session->waitForElementVisible('css', '.ck-editor__editable .vale-mark--error');
    $this->assertNotNull($mark);

    // Editing a marked word drops its mark.
    $this->editor("const root = editor.model.document.getRoot(); editor.model.change((writer) => writer.insertText('x', writer.createPositionAt(root.getChild(0), 10)));");
    $this->assertTrue($session->waitForElementRemoved('css', '.ck-editor__editable .vale-mark--error'));
    $this->editor("editor.setData('<p>This is <strong>very</strong> good, really.</p>');");
    $this->assertNotNull($session->waitForElementVisible('css', '.ck-editor__editable .vale-mark--error'));

    // The toolbar button hides the marks, and shows them again.
    $button = $session->elementExists('xpath', '//div[contains(@class, "form-item-field-rich-0-value")]//button[.//span[text()="Vale findings"]]');
    $this->assertSame('true', $button->getAttribute('aria-pressed'));
    $button->click();
    $this->assertTrue($session->waitForElementRemoved('css', '.ck-editor__editable .vale-mark'));
    $this->assertSame('false', $button->getAttribute('aria-pressed'));
    $button->click();
    $this->assertNotNull($session->waitForElementVisible('css', '.ck-editor__editable .vale-mark--error'));

    // A check that fails keeps the marks, and the button says the text was
    // not checked. The fake Vale fails on FAKE_CRASH.
    $this->editor("const root = editor.model.document.getRoot(); editor.model.change((writer) => writer.insertText(' FAKE_CRASH', writer.createPositionAt(root.getChild(0), 'end')));");
    $this->assertNotNull($session->waitForElement('css', '.vale-findings-button--unchecked'));
    $session->elementExists('css', '.ck-editor__editable .vale-mark--error');
    $session->elementExists('css', '.ck-editor__editable .vale-mark--suggestion');

    // The next check that works clears that.
    $this->editor("editor.setData('<p>This is <strong>very</strong> good, really.</p>');");
    $this->assertTrue($session->waitForElementRemoved('css', '.vale-findings-button--unchecked'));
    $this->assertNotNull($session->waitForElementVisible('css', '.ck-editor__editable .vale-mark--error'));
  }

}
