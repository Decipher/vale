/**
 * @file
 * Prepares a value for Vale's browser build, and reads what it gives.
 *
 * Each step does what the server does before and after it runs Vale, in
 * ValeRunner and FieldSettings, so the browser build gives the alerts that
 * a save would. It runs on the page, in the worker and in Node.
 */

/* global globalThis */

((global) => {
  const LEVELS = ['suggestion', 'warning', 'error'];

  /**
   * Gives a safe file extension, as ValeRunner does.
   *
   * @param {string} extension
   *   An extension, with or without its dot.
   *
   * @return {string}
   *   The extension in lower case, or "html" when it is not plain letters
   *   and digits.
   */
  function clean(extension) {
    const lower = String(extension).replace(/^\.+/, '').toLowerCase();
    return /^[a-z0-9]{1,10}$/.test(lower) ? lower : 'html';
  }

  global.ValeCommon = {
    /**
     * Sets the lowest level Vale reports to suggestion.
     *
     * The server runs Vale with --minAlertLevel=suggestion, and the field's
     * settings decide what is reported. The browser build has no flags, so
     * the configuration says it instead.
     *
     * @param {string} ini
     *   The configuration.
     *
     * @return {string}
     *   The configuration, with only this MinAlertLevel at the top.
     */
    config(ini) {
      let top = true;
      // Each line keeps its line break. This needs no lookbehind, which
      // Safari before 16.4 does not support.
      const lines = ini.match(/[^\n]*\n|[^\n]+/g) || [''];
      const kept = lines.filter((line) => {
        top = top && !/^\s*\[/.test(line);
        return !(top && /^\s*MinAlertLevel\s*=/i.test(line));
      });
      return `MinAlertLevel = suggestion\n${kept.join('')}`;
    },

    /**
     * Gives the extension a value is linted with, as FieldLinter does.
     *
     * @param {object} field
     *   The field's settings, from drupalSettings.vale.fields.
     * @param {string|null} format
     *   The value's text format, or NULL.
     * @param {object} formats
     *   The extension for each text format, and the default one.
     *
     * @return {string}
     *   The extension, such as "md", "html" or "txt".
     */
    extension(field, format, formats) {
      if (field.plain) {
        return 'txt';
      }
      const map = formats.formats || {};
      return clean(
        format !== null && typeof map[format] === 'string'
          ? map[format]
          : formats.defaultExtension,
      );
    },

    /**
     * Gives the name a value is linted under, with its extension.
     *
     * The browser build reads a value as "input." and this. The slash stops
     * a section that starts with "*" from matching more than it does on the
     * server, so one such as [*.field_summary.*] applies as it does there.
     * A section that does not start with "*" matches on the server only.
     *
     * @param {object} field
     *   The field's settings.
     * @param {string} extension
     *   The extension.
     *
     * @return {string}
     *   The name, such as "/node.page.body.md".
     */
    format(field, extension) {
      const name = String(field.name).replace(/[^A-Za-z0-9_.-]/g, '_');
      return `/${name || 'text'}.${extension}`;
    },

    /**
     * Gives the text Vale lints.
     *
     * Vale lints only the block content of an HTML document, so a fragment
     * goes in a document, on lines of its own, as on the server.
     *
     * @param {string} text
     *   The value.
     * @param {string} extension
     *   The extension.
     *
     * @return {string}
     *   The text to lint.
     */
    input(text, extension) {
      return extension === 'html' || extension === 'htm'
        ? `<html><body>\n${text}\n</body></html>\n`
        : text;
    },

    /**
     * Reads Vale's alerts, and keeps those the field's settings report.
     *
     * @param {Array<object>} items
     *   The alerts, as Vale's JSON output gives them.
     * @param {string} text
     *   The value.
     * @param {string} extension
     *   The extension it was linted with.
     * @param {object} field
     *   The field's settings.
     * @param {object} context
     *   The entity_type, bundle, id, field and delta of the value.
     *
     * @return {Array<object>}
     *   The alerts, in the shape meta.vale gives them.
     */
    alerts(items, text, extension, field, context) {
      const html = extension === 'html' || extension === 'htm';
      const lines = text.split(/\r\n|\r|\n/).length;
      const disabled = field.disabledRules || [];
      const levels = field.levelOverrides || {};
      const minimum = LEVELS.indexOf(field.minLevel || 'suggestion');
      const alerts = [];
      items.forEach((item) => {
        if (
          typeof item.Check !== 'string' ||
          !LEVELS.includes(item.Severity) ||
          !Array.isArray(item.Span)
        ) {
          return;
        }
        let line = item.Line;
        if (html) {
          // The document's first line is the wrapper.
          if (line <= 1 || line > lines + 1) {
            return;
          }
          line -= 1;
        }
        if (disabled.includes(item.Check)) {
          return;
        }
        const level = levels[item.Check] || item.Severity;
        if (LEVELS.indexOf(level) < minimum) {
          return;
        }
        alerts.push({
          entity_type: context.entity_type,
          bundle: context.bundle,
          id: context.id === undefined ? null : context.id,
          field: context.field,
          delta: context.delta || 0,
          rule: item.Check,
          level,
          message: item.Message || '',
          line,
          start: item.Span[0],
          end: item.Span[1],
          match: item.Match || '',
          description: item.Description || '',
          link: item.Link || '',
        });
      });
      return alerts;
    },
  };
})(globalThis);
