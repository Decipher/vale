/**
 * @file
 * Runs vale's JavaScript tests with Node's test runner.
 *
 * npm runs this from the repository root, and from build/ in CI. It finds
 * the tests from its own place, so it works from both, in any shell.
 */

/* eslint-env node */

const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const directory = path.join(root, 'tests', 'js');
const files = fs.existsSync(directory)
  ? fs
      .readdirSync(directory)
      .filter((name) => name.endsWith('.test.js'))
      .sort()
      .map((name) => path.join('tests', 'js', name))
  : [];

if (files.length === 0) {
  // eslint-disable-next-line no-console
  console.log('No JavaScript tests found.');
  process.exit(0);
}

const result = spawnSync(process.execPath, ['--test', ...files], {
  cwd: root,
  stdio: 'inherit',
});
// A test runner that a signal stopped has no status.
process.exit(result.status === null ? 1 : result.status);
