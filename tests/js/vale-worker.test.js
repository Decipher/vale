/**
 * @file
 * Tests what the worker asks the site for, in plain Node.
 *
 * The worker runs in a sandbox with a stub fetch and a stub engine, so no
 * browser and no vale.wasm are needed.
 */

/* eslint-env node */
/* global globalThis */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

require('../../js/vale-common');

const { ValeCommon } = globalThis;

// A test whose value is never checked fails instead of hanging.
const LIMIT = { timeout: 5000 };

const source = fs.readFileSync(
  path.join(__dirname, '../../js/vale-worker.js'),
  'utf8',
);

const URLS = {
  loader: '/wasm_exec.js',
  engine: '/vale-engine.js',
  common: '/vale-common.js',
  module: '/vale.wasm',
  config: '/vale/config/LANGCODE',
  styles: '/vale/styles?v=abc',
  models: '/vale/models/LANGCODE?v=abc',
  modelLanguages: ['fr'],
};

/**
 * Starts the worker in a sandbox.
 *
 * @param {object} [options]
 *   - urls: what the first message gives, in place of URLS.
 *   - status: the HTTP status of each URL that is not 200, by URL.
 *
 * @return {object}
 *   - send: posts a message to the worker, and gives its answer.
 *   - fetched: each fetch, as its URL and cache mode, in order.
 *   - packages: how many packages the engine was given.
 *   - release: lets the fetches that wait answer.
 */
function start(options = {}) {
  const urls = options.urls || URLS;
  const status = options.status || {};
  const fetched = [];
  const waiting = [];
  let held = Boolean(options.hold);
  let packages = 0;
  let listener = null;
  const answers = {};
  const sandbox = {
    ValeCommon,
    ValeEngine: {
      ready: () => Promise.resolve(),
      addPackage: () => {
        packages += 1;
      },
      lint: () => [],
    },
    Promise,
    Uint8Array,
    Error,
    Array,
    encodeURIComponent,
    fetch: (url, init) => {
      fetched.push({ url, cache: init.cache });
      const code = status[url] || 200;
      const response = {
        status: code,
        ok: code >= 200 && code < 300,
        text: () => Promise.resolve('StylesPath = /styles\n'),
        arrayBuffer: () => Promise.resolve(new ArrayBuffer(4)),
      };
      if (!held) {
        return Promise.resolve(response);
      }
      return new Promise((resolve) => {
        waiting.push(() => resolve(response));
      });
    },
    importScripts: () => {},
    addEventListener: (type, callback) => {
      listener = callback;
    },
    postMessage: (message) => {
      // Copied into this realm, to compare it.
      answers[message.id](JSON.parse(JSON.stringify(message)));
    },
  };
  sandbox.globalThis = sandbox;
  vm.runInNewContext(source, sandbox);
  let next = 0;
  return {
    fetched,
    packages: () => packages,
    release: () => {
      held = false;
      waiting.splice(0).forEach((answer) => answer());
    },
    send: (message) =>
      new Promise((resolve) => {
        next += 1;
        answers[next] = resolve;
        listener({ data: { ...message, id: next } });
      }),
    init: function init() {
      return this.send({ type: 'init', urls });
    },
  };
}

test(
  'the styles download while the build loads, and are kept by fingerprint',
  LIMIT,
  async () => {
    const worker = start();
    assert.deepEqual(await worker.init(), { id: 1, ok: true });
    assert.deepEqual(worker.fetched, [
      { url: '/vale/styles?v=abc', cache: 'default' },
    ]);
  },
);

test('a language is asked for all at once', LIMIT, async () => {
  const worker = start({ hold: true });
  const ready = worker.init();
  worker.release();
  await ready;
  const answer = worker.send({ type: 'lint', text: 'a', langcode: 'fr' });
  // Nothing has answered yet, and every request is out.
  await new Promise((resolve) => {
    setImmediate(resolve);
  });
  assert.deepEqual(worker.fetched.slice(1), [
    { url: '/vale/config/fr', cache: 'no-cache' },
    { url: '/vale/models/fr?v=abc', cache: 'default' },
  ]);
  assert.deepEqual(await answer, { id: 2, alerts: [] });
  // The styles and the model.
  assert.equal(worker.packages(), 2);

  // A second value in the language asks for nothing more.
  await worker.send({ type: 'lint', text: 'b', langcode: 'fr' });
  assert.equal(worker.fetched.length, 3);
  assert.equal(worker.packages(), 2);
});

test('a language with no model is not asked for one', LIMIT, async () => {
  const worker = start();
  await worker.init();
  assert.deepEqual(
    await worker.send({ type: 'lint', text: 'a', langcode: 'en' }),
    { id: 2, alerts: [] },
  );
  assert.deepEqual(
    worker.fetched.map((request) => request.url),
    ['/vale/styles?v=abc', '/vale/config/en'],
  );
  assert.equal(worker.packages(), 1);
});

test('with no list, every language is asked for its model', LIMIT, async () => {
  const urls = { ...URLS };
  delete urls.modelLanguages;
  const worker = start({ urls, status: { '/vale/models/en?v=abc': 404 } });
  await worker.init();
  // A language with no model answers 404, which is no error.
  assert.deepEqual(
    await worker.send({ type: 'lint', text: 'a', langcode: 'en' }),
    { id: 2, alerts: [] },
  );
  assert.ok(
    worker.fetched.some((request) => request.url === '/vale/models/en?v=abc'),
  );
  assert.equal(worker.packages(), 1);
});

test('styles that failed to download are tried again', LIMIT, async () => {
  const status = { '/vale/styles?v=abc': 500 };
  const worker = start({ status });
  await worker.init();
  const failed = await worker.send({ type: 'lint', text: 'a', langcode: 'en' });
  assert.equal(failed.error, '/vale/styles?v=abc: HTTP 500');
  delete status['/vale/styles?v=abc'];
  assert.deepEqual(
    await worker.send({ type: 'lint', text: 'a', langcode: 'en' }),
    { id: 3, alerts: [] },
  );
  assert.equal(worker.packages(), 1);
});

test('a language with no configuration is an error', LIMIT, async () => {
  const worker = start({ status: { '/vale/config/de': 404 } });
  await worker.init();
  const answer = await worker.send({ type: 'lint', text: 'a', langcode: 'de' });
  assert.equal(answer.error, 'No Vale configuration for de.');
});
