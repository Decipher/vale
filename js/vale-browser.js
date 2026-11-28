/**
 * @file
 * Lints a value in the browser, with Vale's WebAssembly build.
 *
 * Editor plugins call Drupal.vale.lint(). The first call starts a Web Worker
 * that loads vale.wasm and the site's configuration. A value is checked with
 * POST /vale/check instead when the browser build is missing, does not load,
 * or the worker dies or stops answering. Then every later value is too. A
 * value the browser build fails to check is also sent to the server. The
 * server still checks every save.
 */

((Drupal, drupalSettings, ValeCommon) => {
  let worker = null;
  let broken = false;
  let next = 0;
  const pending = {};

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
   * Checks a value with the server, as the browser build would.
   *
   * @param {object} value
   *   The value, as Drupal.vale.lint() takes it.
   *
   * @return {Promise<{checked: boolean, alerts: Array<object>}>}
   *   The result.
   */
  async function server(value) {
    const urls = drupalSettings.vale.browser;
    const token = await fetch(urls.token, { credentials: 'same-origin' }).then(
      (response) => response.text(),
    );
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
    const response = await fetch(urls.check, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
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
    try {
      await start();
    } catch (e) {
      // The browser build is missing or does not load.
      broken = true;
      return server(value);
    }
    try {
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
    } catch (e) {
      // The browser build could not check this value.
      return server(value);
    }
  };
})(Drupal, drupalSettings, window.ValeCommon);
