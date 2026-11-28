/**
 * @file
 * Lints a value in the browser, with Vale's WebAssembly build.
 *
 * Editor plugins call Drupal.vale.lint(). The first call starts a Web Worker
 * that loads vale.wasm and the site's configuration. That takes a while, so
 * until the build has loaded each value also goes to POST /vale/check, and
 * the first to answer gives the findings. A value is checked on the server
 * alone when the browser build is missing, does not
 * load, or the worker dies or stops answering. Then every later value is
 * too. A value the browser build fails to check is also sent to the server.
 * The server still checks every save.
 */

((Drupal, drupalSettings, ValeCommon) => {
  let worker = null;
  let broken = false;
  let next = 0;
  const pending = {};

  // The server runs only a few checks at once, and answers 503 to the rest.
  // So at most this many checks are sent at once, and the others wait.
  const IN_FLIGHT = 2;

  // How long to wait before sending a check the server was too busy for
  // again, in milliseconds, when its answer has no Retry-After header.
  const RETRY_DELAY = 1000;

  // The longest wait a Retry-After header can ask for, in milliseconds.
  const RETRY_MAX = 10000;

  // TRUE once the browser build has loaded.
  let loaded = false;

  // The CSRF token the server check needs, once it is asked for.
  let token = null;

  let sending = 0;
  const queue = [];

  /**
   * How long to wait for the worker to answer, in milliseconds.
   *
   * The first answer loads vale.wasm and the styles, so this is generous.
   */
  const TIMEOUT = 30000;

  /**
   * Stops using the worker, so every later value goes to the server.
   *
   * @param {Error} error
   *   Why, which each waiting message is rejected with.
   */
  function fail(error) {
    broken = true;
    if (worker) {
      worker.terminate();
    }
    Object.keys(pending).forEach((id) => {
      pending[id].reject(error);
      delete pending[id];
    });
  }

  /**
   * Sends a message to the worker, and waits for its answer.
   *
   * A worker that does not answer in time is taken to be stuck.
   *
   * @param {object} message
   *   The message.
   *
   * @return {Promise<object>}
   *   The answer.
   */
  function send(message) {
    return new Promise((resolve, reject) => {
      next += 1;
      const id = next;
      const timer = setTimeout(() => {
        if (pending[id]) {
          fail(new Error('Vale worker did not answer'));
        }
      }, TIMEOUT);
      pending[id] = {
        resolve: (answer) => {
          clearTimeout(timer);
          resolve(answer);
        },
        reject: (error) => {
          clearTimeout(timer);
          reject(error);
        },
      };
      worker.postMessage({ ...message, id });
    });
  }

  /**
   * Starts the worker and loads the browser build, once.
   *
   * @return {Promise<void>}
   *   Resolves once the build is loaded.
   */
  function start() {
    if (!worker) {
      const urls = drupalSettings.vale.browser;
      worker = new Worker(urls.worker);
      worker.addEventListener('message', (event) => {
        const { id, error } = event.data;
        const waiting = pending[id];
        delete pending[id];
        if (waiting && error) {
          waiting.reject(new Error(error));
        } else if (waiting) {
          waiting.resolve(event.data);
        }
      });
      // A worker that dies answers nothing more.
      worker.addEventListener('error', (event) => {
        fail(new Error(event.message || 'Vale worker failed'));
      });
      worker.ready = send({ type: 'init', urls });
    }
    return worker.ready;
  }

  /**
   * Runs a task once fewer than IN_FLIGHT tasks are running.
   *
   * @param {function(): Promise<*>} task
   *   The task.
   *
   * @return {Promise<*>}
   *   What the task gives.
   */
  function queued(task) {
    return new Promise((resolve, reject) => {
      queue.push(() => {
        sending += 1;
        task()
          .then(resolve, reject)
          .finally(() => {
            sending -= 1;
            if (queue.length > 0) {
              queue.shift()();
            }
          });
      });
      if (sending < IN_FLIGHT) {
        queue.shift()();
      }
    });
  }

  /**
   * Waits a while.
   *
   * @param {number} ms
   *   How long, in milliseconds.
   *
   * @return {Promise<void>}
   *   Resolves once the time is up.
   */
  function wait(ms) {
    return new Promise((resolve) => {
      setTimeout(resolve, ms);
    });
  }

  /**
   * Gives how long to wait before a check the server was too busy for.
   *
   * @param {Response} response
   *   The 503 answer.
   *
   * @return {number}
   *   The wait, in milliseconds. It is the Retry-After header's seconds, up to
   *   RETRY_MAX, or RETRY_DELAY when the header is missing or not a number.
   */
  function retryDelay(response) {
    const header = response.headers.get('Retry-After');
    if (header === null || !/^\s*\d+\s*$/.test(header)) {
      return RETRY_DELAY;
    }
    return Math.min(Number(header) * 1000, RETRY_MAX);
  }

  /**
   * Checks a value with the server, as the browser build would.
   *
   * Only a few checks are sent at once. A check the server is too busy for
   * is sent once more, after the wait its answer asks for. It waits out of
   * the queue, so other checks are sent meanwhile.
   *
   * @param {object} value
   *   The value, as Drupal.vale.lint() takes it.
   *
   * @return {Promise<{checked: boolean, alerts: Array<object>}>}
   *   The result.
   */
  async function server(value) {
    const urls = drupalSettings.vale.browser;
    // The token is the session's, so it is asked for once.
    if (!token) {
      token = fetch(urls.token, { credentials: 'same-origin' })
        .then((response) => response.text())
        .catch((e) => {
          token = null;
          throw e;
        });
    }
    const header = await token;
    const body = {
      entity_type: value.entityType,
      bundle: value.bundle,
      field: value.field,
      format: value.format === undefined ? null : value.format,
      langcode: value.langcode || null,
      text: value.text,
    };
    if (value.entityId !== undefined && value.entityId !== null) {
      body.id = value.entityId;
    }
    const post = () =>
      fetch(urls.check, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': header },
        body: JSON.stringify(body),
      });
    let response = await queued(post);
    if (response.status === 503) {
      await wait(retryDelay(response));
      response = await queued(post);
    }
    if (!response.ok) {
      throw new Error(`Vale check: HTTP ${response.status}`);
    }
    const result = await response.json();
    return {
      checked: result.checked,
      alerts: result.alerts.map((alert) => ({
        entity_type: value.entityType,
        bundle: value.bundle,
        id: value.id === undefined ? null : value.id,
        field: value.field,
        delta: value.delta || 0,
        rule: alert.rule,
        level: alert.level,
        message: alert.message,
        line: alert.line,
        start: alert.start,
        end: alert.end,
        match: alert.match,
        description: alert.description,
        link: alert.link,
      })),
    };
  }

  /**
   * Gives the first result of two checks of one value.
   *
   * @param {Promise<object>} one
   *   A check. When both fail, its error is the one given.
   * @param {Promise<object>} other
   *   The other check.
   *
   * @return {Promise<object>}
   *   The result of whichever answers first. A check that fails leaves it
   *   to the other.
   */
  function first(one, other) {
    return new Promise((resolve, reject) => {
      let failed = null;
      let failures = 0;
      const fail = (primary) => (error) => {
        failures += 1;
        if (primary || failed === null) {
          failed = error;
        }
        if (failures === 2) {
          reject(failed);
        }
      };
      one.then(resolve, fail(true));
      other.then(resolve, fail(false));
    });
  }

  /**
   * The Vale API for editor plugins.
   *
   * @namespace
   */
  Drupal.vale = Drupal.vale || {};

  /**
   * Lints a value of a field, in the value's own language.
   *
   * The field must be attached with ValeBrowser::attachments(), which gives
   * its settings in drupalSettings.vale.fields.
   *
   * @param {object} value
   *   The value:
   *   - entityType, bundle, field: the field the value belongs to.
   *   - text: the value.
   *   - format: its text format, or null.
   *   - langcode: the language of its translation, such as "fr".
   *   - id: the entity's UUID, if it has one, for the alerts.
   *   - entityId: the entity's ID, if it has one, for the server check.
   *   - delta: the value's delta, 0 when not given.
   *
   * @return {Promise<{checked: boolean, alerts: Array<object>}>}
   *   The alerts, in the shape the JSON:API meta.vale gives them. checked is
   *   false when the value could not be checked.
   */
  Drupal.vale.lint = async (value) => {
    const settings = drupalSettings.vale;
    const key = `${value.entityType}.${value.bundle}.${value.field}`;
    const field = settings.fields && settings.fields[key];
    if (!field) {
      throw new Error(`${key} is not checked by Vale.`);
    }
    if (value.text.trim() === '') {
      return { checked: true, alerts: [] };
    }
    if (!settings.browser.module || broken) {
      return server(value);
    }
    const extension = ValeCommon.extension(
      field,
      value.format === undefined ? null : value.format,
      settings,
    );
    // Checks the value with the browser build.
    const build = async () => {
      const { alerts } = await send({
        type: 'lint',
        text: ValeCommon.input(value.text, extension),
        format: ValeCommon.format(field, extension),
        langcode: value.langcode || 'und',
      });
      return {
        checked: true,
        alerts: ValeCommon.alerts(alerts, value.text, extension, field, {
          entity_type: value.entityType,
          bundle: value.bundle,
          id: value.id,
          field: value.field,
          delta: value.delta,
        }),
      };
    };
    if (!loaded) {
      // The browser build takes a while to load, and how long depends on
      // the network. So the server checks this value too, and the first of
      // the two to answer gives the findings. The build goes on loading.
      const ready = start().then(
        () => {
          loaded = true;
        },
        () => {
          // The browser build is missing or does not load.
          broken = true;
        },
      );
      return first(
        server(value),
        ready.then(() => {
          if (!loaded) {
            throw new Error('The Vale browser build did not load.');
          }
          return build();
        }),
      );
    }
    try {
      return await build();
    } catch (e) {
      // The browser build could not check this value.
      return server(value);
    }
  };
})(Drupal, drupalSettings, window.ValeCommon);
