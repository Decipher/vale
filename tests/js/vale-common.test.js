/**
 * @file
 * Tests the browser build's shared steps in plain Node.
 */

/* eslint-env node */
/* global globalThis */
// cspell:ignore minalertlevel

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');

require('../../js/vale-common');

const { ValeCommon } = globalThis;

test('config() sets the lowest level at the top', () => {
  assert.equal(
    ValeCommon.config('MinAlertLevel = error\nStylesPath = styles\n'),
    'MinAlertLevel = suggestion\nStylesPath = styles\n',
  );
});

test('config() keeps a MinAlertLevel inside a section', () => {
  assert.equal(
    ValeCommon.config('StylesPath = styles\n[*.md]\nMinAlertLevel = error\n'),
    'MinAlertLevel = suggestion\nStylesPath = styles\n[*.md]\nMinAlertLevel = error\n',
  );
});

test('config() keeps Windows line breaks', () => {
  assert.equal(
    ValeCommon.config('minalertlevel = warning\r\nStylesPath = styles\r\n'),
    'MinAlertLevel = suggestion\nStylesPath = styles\r\n',
  );
});

test('config() keeps a last line with no line break', () => {
  assert.equal(
    ValeCommon.config('StylesPath = styles'),
    'MinAlertLevel = suggestion\nStylesPath = styles',
  );
});

test('config() reads an empty configuration', () => {
  assert.equal(ValeCommon.config(''), 'MinAlertLevel = suggestion\n');
});

// Safari before 16.4 cannot parse a regular expression with a lookbehind,
// and one in any of these files stops the whole file loading there.
test('the browser files use no lookbehind', () => {
  const directory = path.join(__dirname, '../../js');
  fs.readdirSync(directory)
    .filter((file) => file.endsWith('.js'))
    .forEach((file) => {
      const source = fs.readFileSync(path.join(directory, file), 'utf8');
      assert.doesNotMatch(source, /\(\?<[=!]/, file);
    });
});
