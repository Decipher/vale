/**
 * @file
 * Tests how the findings under a field are counted, sorted and opened.
 */

/* eslint-env node */
/* global globalThis */

const assert = require('node:assert/strict');
const { test } = require('node:test');

require('../../js/vale-text');

const { ValeText } = globalThis;

/**
 * Gives findings of the levels given, each named by its place.
 *
 * @param {Array<string>} levels
 *   The levels.
 *
 * @return {Array<object>}
 *   The findings.
 */
function findings(levels) {
  return levels.map((level, index) => ({
    alert: { level, rule: `R${index}` },
  }));
}

test('levelCounts() counts each level', () => {
  assert.deepEqual(ValeText.levelCounts([]), {
    error: 0,
    warning: 0,
    suggestion: 0,
  });
  assert.deepEqual(
    ValeText.levelCounts(
      findings(['warning', 'error', 'suggestion', 'warning', 'error']),
    ),
    { error: 2, warning: 2, suggestion: 1 },
  );
  // A level Vale does not have counts as a suggestion.
  assert.deepEqual(ValeText.levelCounts(findings(['note'])), {
    error: 0,
    warning: 0,
    suggestion: 1,
  });
});

test('byLevel() puts errors first and keeps the order in a level', () => {
  const list = findings([
    'suggestion',
    'warning',
    'error',
    'note',
    'warning',
    'error',
  ]);
  assert.deepEqual(
    ValeText.byLevel(list).map((finding) => finding.alert.rule),
    ['R2', 'R5', 'R1', 'R4', 'R0', 'R3'],
  );
  // The list given is left as it was.
  assert.equal(list[0].alert.rule, 'R0');
});

test('opens() opens for an error, until the author chooses', () => {
  const clean = ValeText.levelCounts(findings(['warning', 'suggestion']));
  const broken = ValeText.levelCounts(findings(['warning', 'error']));
  // By itself, the list is open only for an error.
  assert.equal(ValeText.opens(clean, false, false), false);
  assert.equal(ValeText.opens(broken, false, false), true);
  assert.equal(ValeText.opens(clean, false, true), false);
  // The author's choice stays, whatever a later check finds.
  assert.equal(ValeText.opens(broken, true, false), false);
  assert.equal(ValeText.opens(clean, true, true), true);
});

test('formatOf() finds the format by the name of the value', () => {
  const format = { tagName: 'SELECT', value: 'basic_html' };
  const wrapped = { tagName: 'INPUT', value: 'plain_text' };
  const element = (name, elements, wrapper) => ({
    name,
    form: elements ? { elements } : null,
    closest: () => wrapper || null,
  });
  // Core names the format as the value, with "format" for "value". A theme
  // may leave out the wrapper around the two.
  assert.equal(
    ValeText.formatOf(element('body[0][value]', { 'body[0][format]': format })),
    'basic_html',
  );
  // With no such name, the wrapper is searched.
  assert.equal(
    ValeText.formatOf(element('other', {}, { querySelector: () => wrapped })),
    'plain_text',
  );
  assert.equal(
    ValeText.formatElement(
      element('body[0][value]', {}, { querySelector: () => wrapped }),
    ),
    wrapped,
  );
  // A plain field has no format.
  assert.equal(ValeText.formatOf(element('title[0][value]', {})), null);
  assert.equal(ValeText.formatOf(element('title[0][value]', null)), null);
});
