/**
 * @file
 * Tests how the vale/browser library falls back to the server, in plain Node.
 *
 * The library runs in a sandbox with a stub Worker, fetch and timers, so no
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
  path.join(__dirname, '../../js/vale-browser.js'),
  'utf8',
);

/**
 * Copies a value the sandbox made into this realm, to compare it.
 *
 * @param {*} data
 *   The value.
 *
 * @return {*}
 *   The copy.
 */
function plain(data) {
  return JSON.parse(JSON.stringify(data));
}

/**
 * Lets every pending promise callback run.
 *
 * @return {Promise<void>}
 *   Resolves once they have.
 */
function settle() {
  return new Promise((resolve) => {
    setImmediate(resolve);
  });
}

/**
 * Gives a stub clock, which runs a timer only when told to.
 *
 * @return {object}
 *   The clock.
 */
function clock() {
  let now = 0;
  let last = 0;
  const timers = new Map();
  return {
    setTimeout(callback, ms) {
      last += 1;
      timers.set(last, { callback, at: now + (ms || 0) });
      return last;
    },
    clearTimeout(id) {
      timers.delete(id);
    },
    // Moves the time on, and runs each timer that is due, in order.
    async tick(ms) {
      const end = now + ms;
      for (;;) {
        const due = [...timers.entries()]
          .filter(([, timer]) => timer.at <= end)
          .sort((a, b) => a[1].at - b[1].at);
        if (due.length === 0) {
          break;
        }
        const [id, timer] = due[0];
        timers.delete(id);
        now = timer.at;
        timer.callback();
        // eslint-disable-next-line no-await-in-loop
        await settle();
      }
      now = end;
      await settle();
    },
  };
}

/**
 * Gives a stub Response.
 *
 * @param {number} status
 *   The HTTP status.
 * @param {object} body
 *   The JSON body.
 * @param {object} headers
 *   The headers.
 *
 * @return {object}
 *   The response.
 */
function response(status, body = {}, headers = {}) {
  return {
    status,
    ok: status >= 200 && status < 300,
    headers: { get: (name) => (name in headers ? headers[name] : null) },
    json: async () => body,
    text: async () => 'token',
  };
}

/**
 * Loads the library in a sandbox.
 *
 * @param {object} options
 *   - module: the browser build's URL, or null when it is missing.
 *   - worker: called with each message the stub worker gets, and the worker.
 *   - check: called with the text of each POST /vale/check, gives its
 *     response or a promise of it.
 *
 * @return {object}
 *   The sandbox: Drupal, the clock, the workers made and the texts checked.
 */
function load(options = {}) {
  const time = clock();
  const workers = [];
  const checked = [];
  class Worker {
    constructor(url) {
      this.url = url;
      this.listeners = { message: [], error: [] };
      this.messages = [];
      this.terminated = false;
      workers.push(this);
    }

    addEventListener(type, listener) {
      this.listeners[type].push(listener);
    }

    postMessage(message) {
      this.messages.push(message);
      if (options.worker) {
        options.worker(message, this);
      }
    }

    // Answers a message, as the worker would.
    answer(data) {
      this.listeners.message.forEach((listener) => listener({ data }));
    }

    // Dies, as a worker whose script throws would.
    die(message) {
      this.listeners.error.forEach((listener) => listener({ message }));
    }

    terminate() {
      this.terminated = true;
    }
  }
  const fetch = async (url, init) => {
    if (url === '/session/token') {
      return response(200);
    }
    const { text } = JSON.parse(init.body);
    checked.push(text);
    return options.check
      ? options.check(text)
      : response(200, { checked: true, alerts: [] });
  };
  const Drupal = {};
  const sandbox = {
    Drupal,
    drupalSettings: {
      vale: {
        browser: {
          module: options.module === undefined ? '/vale.wasm' : options.module,
          worker: '/vale-worker.js',
          check: '/vale/check',
          token: '/session/token',
        },
        formats: {},
        defaultExtension: 'md',
        fields: {
          'node.page.body': {
            name: 'Vale',
            plain: true,
            minLevel: 'suggestion',
            disabledRules: [],
            levelOverrides: {},
          },
        },
      },
    },
    window: { ValeCommon },
    Worker,
    fetch,
    setTimeout: time.setTimeout,
    clearTimeout: time.clearTimeout,
  };
  vm.runInNewContext(source, sandbox);
  return { Drupal, time, workers, checked };
}

/**
 * Gives a value of the test field.
 *
 * @param {string} text
 *   The text.
 *
 * @return {object}
 *   The value, as Drupal.vale.lint() takes it.
 */
function value(text) {
  return { entityType: 'node', bundle: 'page', field: 'body', text };
}

test('a worker silent for 30 seconds falls back', LIMIT, async () => {
  // The worker never answers.
  const page = load();
  const result = page.Drupal.vale.lint(value('First.'));
  await settle();
  assert.equal(page.workers.length, 1);
  assert.deepEqual(page.checked, []);

  // It still waits just before the time is up.
  await page.time.tick(29999);
  assert.deepEqual(page.checked, []);
  assert.equal(page.workers[0].terminated, false);

  await page.time.tick(1);
  assert.equal(page.workers[0].terminated, true);
  assert.deepEqual(plain(await result), { checked: true, alerts: [] });
  assert.deepEqual(page.checked, ['First.']);

  // Every later value goes to the server, with no new worker.
  await page.Drupal.vale.lint(value('Second.'));
  assert.equal(page.workers.length, 1);
  assert.equal(page.workers[0].messages.length, 1);
  assert.deepEqual(page.checked, ['First.', 'Second.']);
});

test('a worker silent after it loads falls back', LIMIT, async () => {
  // The worker loads, then never answers a lint.
  const page = load({
    worker: (message, worker) => {
      if (message.type === 'init') {
        worker.answer({ id: message.id });
      }
    },
  });
  const result = page.Drupal.vale.lint(value('First.'));
  await settle();
  assert.equal(page.workers[0].messages.length, 2);
  await page.time.tick(30000);
  await result;
  assert.equal(page.workers[0].terminated, true);
  assert.deepEqual(page.checked, ['First.']);

  await page.Drupal.vale.lint(value('Second.'));
  assert.equal(page.workers[0].messages.length, 2);
  assert.deepEqual(page.checked, ['First.', 'Second.']);
});

test('a worker that dies stays on the server', LIMIT, async () => {
  const page = load({
    worker: (message, worker) => {
      if (message.type === 'init') {
        worker.answer({ id: message.id });
      } else {
        worker.answer({ id: message.id, alerts: [] });
      }
    },
  });
  // A working worker checks the value itself.
  assert.deepEqual(plain(await page.Drupal.vale.lint(value('First.'))), {
    checked: true,
    alerts: [],
  });
  assert.deepEqual(page.checked, []);

  page.workers[0].die('vale.wasm aborted');
  assert.equal(page.workers[0].terminated, true);
  await page.Drupal.vale.lint(value('Second.'));
  await page.Drupal.vale.lint(value('Third.'));
  assert.equal(page.workers.length, 1);
  assert.equal(page.workers[0].messages.length, 2);
  assert.deepEqual(page.checked, ['Second.', 'Third.']);
});

test('a build that does not load stays on the server', LIMIT, async () => {
  const page = load({
    worker: (message, worker) => {
      worker.answer({ id: message.id, error: 'No vale.wasm' });
    },
  });
  await page.Drupal.vale.lint(value('First.'));
  await page.Drupal.vale.lint(value('Second.'));
  assert.equal(page.workers.length, 1);
  assert.equal(page.workers[0].messages.length, 1);
  assert.deepEqual(page.checked, ['First.', 'Second.']);
});

test('a site with no browser build checks on the server', LIMIT, async () => {
  const page = load({ module: null });
  await page.Drupal.vale.lint(value('First.'));
  assert.equal(page.workers.length, 0);
  assert.deepEqual(page.checked, ['First.']);
});
