/**
 * @file
 * Lints values with Vale's browser build in Node, as the vale/browser
 * library does in a worker.
 *
 * BrowserParityTest runs it. The input is a JSON file:
 * - loader, module: the paths of wasm_exec.js and vale.wasm.
 * - engine, common: the paths of js/vale-engine.js and js/vale-common.js.
 * - ini: the configuration the config route gives.
 * - packages: the paths of the zips the styles and models routes give.
 * - formats: the formats and default extension, as drupalSettings.vale has.
 * - values: each with text, format, field and context.
 * It prints the alerts for each value, as JSON.
 *
 * It sets no global fs, as Vale's docs say: wasm_exec.js then uses its own
 * stub, which is what the module expects.
 */

/* eslint-env node */
/* global ValeEngine, ValeCommon */

const fs = require('node:fs');

const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
globalThis.require = require;

// wasm_exec.js and the module's scripts set globals, as they do in a worker.
require(input.loader);
require(input.engine);
require(input.common);

(async () => {
  await ValeEngine.ready(new Uint8Array(fs.readFileSync(input.module)));
  input.packages.forEach((zip) => {
    ValeEngine.addPackage('/styles', new Uint8Array(fs.readFileSync(zip)));
  });
  const ini = ValeCommon.config(input.ini);
  const results = input.values.map((value) => {
    const extension = ValeCommon.extension(
      value.field,
      value.format,
      input.formats,
    );
    const alerts = ValeEngine.lint(
      ValeCommon.input(value.text, extension),
      ValeCommon.format(value.field, extension),
      ini,
    );
    return ValeCommon.alerts(
      alerts,
      value.text,
      extension,
      value.field,
      value.context,
    );
  });
  process.stdout.write(JSON.stringify(results));
  process.exit(0);
})().catch((e) => {
  process.stderr.write(`${e && e.stack ? e.stack : e}\n`);
  process.exit(1);
});
