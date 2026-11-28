/**
 * @file
 * Tests when the vale/browser library checks on the server, in plain Node.
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
 * Gives a stub server that answers each check only when told to.
 *
 * @return {object}
 *   The server: check() for load(), and answer() to answer a check.
 */
function busy() {
  const waiting = [];
  return {
    check: (text) =>
      new Promise((resolve) => {
        waiting.push({ text, resolve });
      }),
    // Answers the oldest check of the text that is still waiting.
    answer(text, status = 200, headers = {}) {
      const index = waiting.findIndex((check) => check.text === text);
      assert.notEqual(index, -1, `${text} is not waiting.`);
      const [check] = waiting.splice(index, 1);
      check.resolve(response(status, { checked: true, alerts: [] }, headers));
      return settle();
    },
  };
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

test(
  'the build answers first when it loads before the server',
  LIMIT,
  async () => {
    const server = busy();
    const page = load({
      worker: (message, worker) => {
        worker.answer(
          message.type === 'init'
            ? { id: message.id }
            : { id: message.id, alerts: [] },
        );
      },
      check: server.check,
    });
    // The server has the value too, and has not answered.
    const result = page.Drupal.vale.lint(value('First.'));
    assert.deepEqual(plain(await result), { checked: true, alerts: [] });
    assert.deepEqual(page.checked, ['First.']);
    assert.equal(page.workers[0].messages.length, 2);

    // Its late answer changes nothing, and the next value skips it.
    await server.answer('First.');
    await page.Drupal.vale.lint(value('Second.'));
    assert.deepEqual(page.checked, ['First.']);
    assert.equal(page.workers[0].messages.length, 3);
  },
);

test('the server checks each value until the build loads', LIMIT, async () => {
  // The worker loads only when the test says so.
  let init = null;
  const page = load({
    worker: (message, worker) => {
      if (message.type === 'init') {
        init = () => worker.answer({ id: message.id });
      } else {
        worker.answer({ id: message.id, alerts: [] });
      }
    },
  });
  // The build starts to load, and the server checks the value meanwhile.
  assert.deepEqual(plain(await page.Drupal.vale.lint(value('First.'))), {
    checked: true,
    alerts: [],
  });
  assert.equal(page.workers.length, 1);
  assert.equal(page.workers[0].messages.length, 1);
  assert.deepEqual(page.checked, ['First.']);

  // It still loads, with the one worker.
  await page.Drupal.vale.lint(value('Second.'));
  assert.equal(page.workers.length, 1);
  assert.equal(page.workers[0].messages.length, 1);
  assert.deepEqual(page.checked, ['First.', 'Second.']);

  // Once it has loaded, it checks the two values it was also given, to
  // no use, and each later value by itself.
  init();
  await settle();
  assert.equal(page.workers[0].messages.length, 3);
  await page.Drupal.vale.lint(value('Third.'));
  assert.equal(page.workers[0].messages.length, 4);
  assert.equal(page.workers[0].messages[3].type, 'lint');
  assert.deepEqual(page.checked, ['First.', 'Second.']);
});

test('a value the server refuses waits for the build', LIMIT, async () => {
  let init = null;
  const page = load({
    worker: (message, worker) => {
      if (message.type === 'init') {
        init = () => worker.answer({ id: message.id });
      } else {
        worker.answer({ id: message.id, alerts: [] });
      }
    },
    check: () => response(429),
  });
  const result = page.Drupal.vale.lint(value('First.'));
  await settle();
  assert.deepEqual(page.checked, ['First.']);
  assert.equal(page.workers[0].messages.length, 1);

  // The build checks it once it has loaded.
  init();
  assert.deepEqual(plain(await result), { checked: true, alerts: [] });
  assert.equal(page.workers[0].messages.length, 2);
  assert.deepEqual(page.checked, ['First.']);
});

test('a value neither can check fails as the server did', LIMIT, async () => {
  const page = load({
    worker: (message, worker) => {
      worker.answer({ id: message.id, error: 'No vale.wasm' });
    },
    check: () => response(429),
  });
  await assert.rejects(page.Drupal.vale.lint(value('First.')), /HTTP 429/);
});

test('a worker silent for 30 seconds falls back', LIMIT, async () => {
  // The worker never answers.
  const page = load();
  await page.Drupal.vale.lint(value('First.'));
  assert.equal(page.workers.length, 1);
  assert.deepEqual(page.checked, ['First.']);

  // It still waits just before the time is up.
  await page.time.tick(29999);
  assert.equal(page.workers[0].terminated, false);

  await page.time.tick(1);
  assert.equal(page.workers[0].terminated, true);

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
  // The build has the first value too, and the server answers it.
  await page.Drupal.vale.lint(value('First.'));
  assert.deepEqual(page.checked, ['First.']);
  assert.equal(page.workers[0].messages.length, 2);
  const result = page.Drupal.vale.lint(value('Second.'));
  await settle();
  assert.equal(page.workers[0].messages.length, 3);
  await page.time.tick(30000);
  await result;
  assert.equal(page.workers[0].terminated, true);
  assert.deepEqual(page.checked, ['First.', 'Second.']);

  await page.Drupal.vale.lint(value('Third.'));
  assert.equal(page.workers[0].messages.length, 3);
  assert.deepEqual(page.checked, ['First.', 'Second.', 'Third.']);
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
  // The server and the build both get the first value, as the build loads.
  await page.Drupal.vale.lint(value('First.'));
  // A working worker checks the next value by itself.
  assert.deepEqual(plain(await page.Drupal.vale.lint(value('Second.'))), {
    checked: true,
    alerts: [],
  });
  assert.deepEqual(page.checked, ['First.']);

  page.workers[0].die('vale.wasm aborted');
  assert.equal(page.workers[0].terminated, true);
  await page.Drupal.vale.lint(value('Third.'));
  await page.Drupal.vale.lint(value('Fourth.'));
  assert.equal(page.workers.length, 1);
  assert.equal(page.workers[0].messages.length, 3);
  assert.deepEqual(page.checked, ['First.', 'Third.', 'Fourth.']);
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

test('at most two checks are sent at once, in order', LIMIT, async () => {
  const server = busy();
  const page = load({ module: null, check: server.check });
  const results = ['A.', 'B.', 'C.', 'D.'].map((text) =>
    page.Drupal.vale.lint(value(text)),
  );
  await settle();
  assert.deepEqual(page.checked, ['A.', 'B.']);

  // A finished check lets the next one go, whichever finishes first.
  await server.answer('B.');
  assert.deepEqual(page.checked, ['A.', 'B.', 'C.']);
  await server.answer('A.');
  assert.deepEqual(page.checked, ['A.', 'B.', 'C.', 'D.']);
  await server.answer('C.');
  await server.answer('D.');
  assert.equal((await Promise.all(results)).length, 4);
});

test('a busy check waits out of the queue, then retries', LIMIT, async () => {
  const server = busy();
  const page = load({ module: null, check: server.check });
  const result = page.Drupal.vale.lint(value('A.'));
  page.Drupal.vale.lint(value('B.'));
  page.Drupal.vale.lint(value('C.'));
  await settle();
  assert.deepEqual(page.checked, ['A.', 'B.']);

  // The busy check gives up its slot while it waits, so C goes now.
  await server.answer('A.', 503, { 'Retry-After': '3' });
  assert.deepEqual(page.checked, ['A.', 'B.', 'C.']);

  // It waits the three seconds the server asked for. Then it joins the
  // queue, behind the two checks still sent.
  await page.time.tick(2999);
  await server.answer('B.');
  assert.deepEqual(page.checked, ['A.', 'B.', 'C.']);
  await page.time.tick(1);
  assert.deepEqual(page.checked, ['A.', 'B.', 'C.', 'A.']);
  await server.answer('A.');
  assert.deepEqual(plain(await result), { checked: true, alerts: [] });

  // A check the server is still too busy for is not sent a third time.
  const again = page.Drupal.vale.lint(value('D.'));
  // The failure comes before the test awaits it, so it is caught here too.
  again.catch(() => {});
  await settle();
  await server.answer('D.', 503, { 'Retry-After': '1' });
  await page.time.tick(1000);
  await server.answer('D.', 503, { 'Retry-After': '1' });
  await page.time.tick(10000);
  await assert.rejects(again, /HTTP 503/);
  assert.deepEqual(page.checked, ['A.', 'B.', 'C.', 'A.', 'D.', 'D.']);
});

/**
 * Checks that a 503 with the headers given is retried after the delay given.
 *
 * @param {object} headers
 *   The headers of the 503.
 * @param {number} delay
 *   The delay expected, in milliseconds.
 */
async function assertRetryAfter(headers, delay) {
  const server = busy();
  const page = load({ module: null, check: server.check });
  page.Drupal.vale.lint(value('A.'));
  await settle();
  await server.answer('A.', 503, headers);
  await page.time.tick(delay - 1);
  assert.deepEqual(page.checked, ['A.'], JSON.stringify(headers));
  await page.time.tick(1);
  assert.deepEqual(page.checked, ['A.', 'A.'], JSON.stringify(headers));
}

test('the wait for a busy server has a default and a cap', LIMIT, async () => {
  const date = 'Wed, 21 Oct 2026 07:28:00 GMT';
  await assertRetryAfter({}, 1000);
  await assertRetryAfter({ 'Retry-After': date }, 1000);
  await assertRetryAfter({ 'Retry-After': '0' }, 0);
  await assertRetryAfter({ 'Retry-After': '2' }, 2000);
  await assertRetryAfter({ 'Retry-After': '3600' }, 10000);
});
