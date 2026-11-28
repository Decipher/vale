/**
 * @file
 * Runs Vale's browser build in a Web Worker.
 *
 * The first message gives the URLs of the build and of the routes that
 * serve the site's configuration. The worker loads the build and the
 * styles together, and each language's configuration and model the first
 * time a value in that language is linted.
 */

/* eslint-env worker */
/* global globalThis, ValeEngine, ValeCommon */

((worker) => {
  let urls = null;
  const fetched = {};
  const styles = {};
  const configs = {};
  const models = {};

  /**
   * Fetches a file the site serves to the browser build.
   *
   * A URL with the styles' fingerprint in "v" names one file for good, so
   * the browser uses its copy without asking. The browser checks its copy
   * of any other file against the ETag, so that one is downloaded again
   * only when it changes.
   *
   * @param {string} url
   *   The URL.
   * @param {string} type
   *   "text" or "bytes".
   *
   * @return {Promise<string|Uint8Array|null>}
   *   The file, or NULL when there is none.
   */
  async function get(url, type) {
    const response = await fetch(url, {
      cache: /[?&]v=[^&]/.test(url) ? 'default' : 'no-cache',
      credentials: 'same-origin',
    });
    if (response.status === 404) {
      return null;
    }
    if (!response.ok) {
      throw new Error(`${url}: HTTP ${response.status}`);
    }
    return type === 'text'
      ? response.text()
      : new Uint8Array(await response.arrayBuffer());
  }

  /**
   * Gives a URL for a language.
   *
   * @param {string} pattern
   *   The URL, with LANGCODE where the langcode goes.
   * @param {string} langcode
   *   The langcode.
   *
   * @return {string}
   *   The URL.
   */
  function forLanguage(pattern, langcode) {
    return pattern.replace('LANGCODE', encodeURIComponent(langcode));
  }

  /**
   * Remembers a promise, and forgets it if it rejects.
   *
   * So a fetch that failed is tried again on the next value.
   *
   * @param {object} store
   *   Where the promise is kept.
   * @param {string} key
   *   Its key in the store.
   * @param {function(): Promise} load
   *   Makes the promise.
   *
   * @return {Promise}
   *   The promise.
   */
  function once(store, key, load) {
    if (!store[key]) {
      store[key] = load().catch((e) => {
        delete store[key];
        throw e;
      });
    }
    return store[key];
  }

  /**
   * Downloads the styles, once.
   *
   * @return {Promise<Uint8Array|null>}
   *   The zip, or NULL when the site has no styles.
   */
  function fetchStyles() {
    return once(fetched, 'styles', () => get(urls.styles, 'bytes'));
  }

  /**
   * Says whether to ask for a language's model.
   *
   * The site names the languages that have one. A language it does not
   * name has none, so asking would only cost a request. With no list,
   * every language is asked for.
   *
   * @param {string} langcode
   *   The langcode.
   *
   * @return {boolean}
   *   TRUE to ask for it.
   */
  function hasModel(langcode) {
    return (
      !Array.isArray(urls.modelLanguages) ||
      urls.modelLanguages.includes(langcode)
    );
  }

  /**
   * Loads what a language needs, once.
   *
   * The styles, the model and the configuration are asked for together.
   *
   * @param {string} langcode
   *   The langcode.
   *
   * @return {Promise<string>}
   *   The configuration for the language.
   */
  async function prepare(langcode) {
    const loaded = await Promise.all([
      once(configs, langcode, () =>
        get(forLanguage(urls.config, langcode), 'text').then((ini) => {
          if (ini === null) {
            throw new Error(`No Vale configuration for ${langcode}.`);
          }
          return ini;
        }),
      ),
      once(styles, 'styles', () =>
        fetchStyles().then((zip) => {
          if (zip) {
            ValeEngine.addPackage('/styles', zip);
          }
        }),
      ),
      once(models, langcode, () =>
        (hasModel(langcode)
          ? get(forLanguage(urls.models, langcode), 'bytes')
          : Promise.resolve(null)
        ).then((zip) => {
          if (zip) {
            ValeEngine.addPackage('/styles', zip);
          }
        }),
      ),
    ]);
    return loaded[0];
  }

  worker.addEventListener('message', async (event) => {
    const { id, type } = event.data;
    try {
      if (type === 'init') {
        urls = event.data.urls;
        worker.importScripts(urls.loader, urls.engine, urls.common);
        // The styles download beside the build. A failure is found, and
        // tried again, when the first value needs them.
        fetchStyles().catch(() => {});
        await ValeEngine.ready(urls.module);
        worker.postMessage({ id, ok: true });
        return;
      }
      const { text, format, langcode } = event.data;
      const ini = ValeCommon.config(await prepare(langcode));
      const alerts = ValeEngine.lint(text, format, ini);
      worker.postMessage({ id, alerts });
    } catch (e) {
      const error = e && e.message ? e.message : String(e);
      worker.postMessage({ id, error });
    }
  });
})(globalThis);
