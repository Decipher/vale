/**
 * @file
 * Loads Vale's browser build once, and calls the functions it installs.
 *
 * Adapted from lib/engine.ts of the Vale browser extension,
 * https://github.com/vale-cli/vale-web, at commit
 * 4a0732a0cc8044e996936b638896b8261d9e6579. Changed to plain JavaScript, to
 * load the module from a URL or from bytes, and to run in a Web Worker or in
 * Node as well as in the extension.
 *
 * MIT License
 *
 * Copyright (c) 2024 Joseph Kato
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

/* global globalThis */

// The loader is wasm_exec.js from the Go toolchain that built the module. It
// must be loaded first, and defines Go. Its filesystem stub is what the
// module expects, so never give it a real fs.
((global) => {
  let loading = null;

  /**
   * Starts the module.
   *
   * @param {string|ArrayBuffer|Uint8Array} source
   *   The URL of vale.wasm, or its bytes.
   *
   * @return {Promise<void>}
   *   Resolves once the module's functions are installed.
   */
  async function load(source) {
    const go = new global.Go();
    let result;
    if (typeof source === 'string') {
      try {
        result = await WebAssembly.instantiateStreaming(
          fetch(source),
          go.importObject,
        );
      } catch (e) {
        // A server that does not send application/wasm stops streaming.
        const response = await fetch(source);
        if (!response.ok) {
          throw new Error(`vale.wasm: HTTP ${response.status}`);
        }
        result = await WebAssembly.instantiate(
          await response.arrayBuffer(),
          go.importObject,
        );
      }
    } else {
      result = await WebAssembly.instantiate(source, go.importObject);
    }

    // main() installs the functions and then waits forever. Its promise
    // settles only if the module dies, which is what we want to hear about.
    let died = null;
    go.run(result.instance)
      .then(() => {
        died = new Error('vale.wasm exited');
      })
      .catch((e) => {
        died = e;
      });

    for (let i = 0; i < 300 && typeof global.ValeLint !== 'function'; i++) {
      if (died) {
        throw died;
      }
      // eslint-disable-next-line no-await-in-loop
      await new Promise((resolve) => {
        setTimeout(resolve, 10);
      });
    }
    if (typeof global.ValeLint !== 'function') {
      throw died || new Error('vale.wasm did not start');
    }
  }

  /**
   * Throws the error a function of the module gave, if any.
   *
   * @param {object} result
   *   What the function gave.
   *
   * @return {object}
   *   The result.
   */
  function unwrap(result) {
    if (result.error) {
      throw new Error(result.error);
    }
    return result;
  }

  /**
   * Reads the alerts from the JSON that ValeLint gives.
   *
   * @param {string|undefined} result
   *   The JSON, keyed by file name.
   *
   * @return {Array<object>}
   *   The alerts for the one file linted.
   */
  function alertsOf(result) {
    const parsed = JSON.parse(result || '{}');
    return Object.values(parsed)[0] || [];
  }

  global.ValeEngine = {
    /**
     * Loads the module on the first call.
     *
     * @param {string|ArrayBuffer|Uint8Array} source
     *   The URL of vale.wasm, or its bytes.
     *
     * @return {Promise<void>}
     *   Resolves once the module is loaded. A failed load can be tried again.
     */
    ready(source) {
      if (!loading) {
        loading = load(source).catch((e) => {
          loading = null;
          throw e;
        });
      }
      return loading;
    },

    /**
     * Says whether the module is loaded.
     *
     * @return {boolean}
     *   TRUE once its functions are installed.
     */
    isReady() {
      return typeof global.ValeLint === 'function';
    },

    /**
     * Lints text.
     *
     * @param {string} text
     *   The text.
     * @param {string} format
     *   The file name it is read as, without "input.", such as "md".
     * @param {string} ini
     *   The configuration.
     *
     * @return {Array<object>}
     *   The alerts, as Vale's JSON output gives them.
     */
    lint(text, format, ini) {
      return alertsOf(unwrap(global.ValeLint(text, format, ini)).result);
    },

    /**
     * Adds a zip's files to the in-memory filesystem.
     *
     * @param {string} prefix
     *   The directory to put them in, such as "/styles".
     * @param {Uint8Array} zip
     *   The zip.
     */
    addPackage(prefix, zip) {
      unwrap(global.ValeAddPackage(prefix, zip));
    },

    /**
     * Empties the in-memory filesystem.
     */
    resetFS() {
      unwrap(global.ValeResetFS());
    },

    /**
     * Lists the files in the in-memory filesystem.
     *
     * @return {string[]}
     *   The paths.
     */
    listFS() {
      return unwrap(global.ValeListFS()).paths || [];
    },
  };
})(globalThis);
