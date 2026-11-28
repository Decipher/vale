/**
 * @file
 * Finds where an alert is in a value, and keeps it there while it changes.
 *
 * offsetsOf(), diff(), shift() and remap() are adapted from lib/text/
 * positions.ts and lib/text/remap.ts of the Vale browser extension,
 * https://github.com/vale-cli/vale-web, at commit
 * 4a0732a0cc8044e996936b638896b8261d9e6579. Changed to plain JavaScript.
 * htmlText() and align() are this module's own.
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

((global) => {
  const wordChar = /[\p{L}\p{N}_'\u2019-]/u;
  const reference = /^&(#x[0-9a-f]+|#[0-9]+|[a-z][a-z0-9]*);/i;

  /**
   * Maps a code point index in a string to a code unit index.
   *
   * @param {string} s
   *   The string.
   * @param {number} cp
   *   The code point index.
   *
   * @return {number|null}
   *   The code unit index, or NULL when the string is too short.
   */
  function unitIndex(s, cp) {
    let units = 0;
    let points = 0;
    while (points < cp) {
      if (units >= s.length) {
        return cp === points ? units : null;
      }
      units += s.codePointAt(units) > 0xffff ? 2 : 1;
      points += 1;
    }
    return units;
  }

  /**
   * Gives one character, the way two texts are compared.
   *
   * @param {string} c
   *   The character.
   *
   * @return {string}
   *   The character, with a no-break space read as a space.
   */
  function normal(c) {
    return c === '\u00a0' ? ' ' : c;
  }

  /**
   * Decodes one HTML character reference.
   *
   * @param {string} entity
   *   The reference, such as "&amp;".
   *
   * @return {string}
   *   The text it stands for.
   */
  function decode(entity) {
    const parsed = new DOMParser().parseFromString(entity, 'text/html');
    return parsed.documentElement.textContent;
  }

  global.ValeText = {
    /**
     * Gives the form element that holds a value's text format.
     *
     * The format is named as the value is, with "format" for "value". A
     * theme may leave out the wrapper core puts around both, so the name is
     * tried first.
     *
     * @param {HTMLElement} element
     *   The text area or text field that holds the value.
     *
     * @return {HTMLElement|null}
     *   The select or hidden input, or NULL for a plain field.
     */
    formatElement(element) {
      const name = element.name || '';
      const named =
        element.form && /\[value\]$/.test(name)
          ? element.form.elements[name.replace(/\[value\]$/, '[format]')]
          : null;
      if (named && named.tagName) {
        return named;
      }
      const wrapper = element.closest('.js-text-format-wrapper');
      return wrapper ? wrapper.querySelector('[name$="[format]"]') : null;
    },

    /**
     * Gives the text format of a value on a form.
     *
     * @param {HTMLElement} element
     *   The text area or text field that holds the value.
     *
     * @return {string|null}
     *   The format the form will save it in, or NULL for a plain field.
     */
    formatOf(element) {
      const format = this.formatElement(element);
      return format ? format.value : null;
    },

    /**
     * Makes one of Vale's icons.
     *
     * The error and warning shapes are those of core's status icons, in
     * core/misc/icons. A screen reader skips an icon, so the word beside it
     * carries the meaning.
     *
     * @param {string} name
     *   "error", "warning", "suggestion", "none", "checking" or "external".
     *
     * @return {SVGElement}
     *   The icon.
     */
    icon(name) {
      const space = 'http://www.w3.org/2000/svg';
      const shapes = {
        error: [
          [
            'path',
            {
              class: 'vale-icon__fill',
              d: 'M8.002 1c-3.868 0-7.002 3.134-7.002 7s3.134 7 7.002 7c3.865 0 7-3.134 7-7s-3.135-7-7-7zm4.025 9.284c.062.063.1.149.1.239 0 .091-.037.177-.1.24l-1.262 1.262c-.064.062-.15.1-.24.1s-.176-.036-.24-.1l-2.283-2.283-2.286 2.283c-.064.062-.15.1-.24.1s-.176-.036-.24-.1l-1.261-1.262c-.063-.062-.1-.148-.1-.24 0-.088.036-.176.1-.238l2.283-2.285-2.283-2.284c-.063-.064-.1-.15-.1-.24s.036-.176.1-.24l1.262-1.262c.063-.063.149-.1.24-.1.089 0 .176.036.24.1l2.285 2.284 2.283-2.284c.064-.063.15-.1.24-.1s.176.036.24.1l1.262 1.262c.062.063.1.149.1.24 0 .089-.037.176-.1.24l-2.283 2.284 2.283 2.284z',
            },
          ],
        ],
        warning: [
          [
            'path',
            {
              class: 'vale-icon__fill',
              d: 'M14.66 12.316l-5.316-10.633c-.738-1.476-1.946-1.476-2.685 0l-5.317 10.633c-.738 1.477.008 2.684 1.658 2.684h10.002c1.65 0 2.396-1.207 1.658-2.684zm-7.66-8.316h2.002v5h-2.002v-5zm2.252 8.615c0 .344-.281.625-.625.625h-1.25c-.345 0-.626-.281-.626-.625v-1.239c0-.344.281-.625.626-.625h1.25c.344 0 .625.281.625.625v1.239z',
            },
          ],
        ],
        suggestion: [
          ['circle', { class: 'vale-icon__fill', cx: 8, cy: 8, r: 7 }],
          ['circle', { class: 'vale-icon__on', cx: 8, cy: 4.7, r: 1.1 }],
          [
            'rect',
            {
              class: 'vale-icon__on',
              x: 7.1,
              y: 6.9,
              width: 1.8,
              height: 5,
              rx: 0.4,
            },
          ],
        ],
        none: [
          ['circle', { class: 'vale-icon__fill', cx: 8, cy: 8, r: 7 }],
          [
            'path',
            { class: 'vale-icon__on-line', d: 'M4.6 8.3l2.2 2.2 4.6-4.8' },
          ],
        ],
        checking: [
          ['circle', { class: 'vale-icon__track', cx: 8, cy: 8, r: 6 }],
          ['path', { class: 'vale-icon__line', d: 'M8 2a6 6 0 0 1 6 6' }],
        ],
        external: [
          [
            'path',
            {
              class: 'vale-icon__line',
              d: 'M6.5 2.5h-4v11h11v-4M9.5 2.5h4v4M13.5 2.5L7.5 8.5',
            },
          ],
        ],
      };
      const svg = document.createElementNS(space, 'svg');
      svg.setAttribute('class', `vale-icon vale-icon--${name}`);
      svg.setAttribute('viewBox', '0 0 16 16');
      svg.setAttribute('width', '16');
      svg.setAttribute('height', '16');
      svg.setAttribute('aria-hidden', 'true');
      svg.setAttribute('focusable', 'false');
      (shapes[name] || shapes.suggestion).forEach(([tag, attributes]) => {
        const shape = document.createElementNS(space, tag);
        Object.keys(attributes).forEach((key) => {
          shape.setAttribute(key, attributes[key]);
        });
        svg.append(shape);
      });
      return svg;
    },

    /**
     * Makes the link to what a rule is about.
     *
     * It opens in a new tab, and its name says so and names the rule.
     *
     * @param {object} alert
     *   The alert, with its rule and link.
     * @param {string} className
     *   The link's class.
     *
     * @return {HTMLAnchorElement}
     *   The link.
     */
    ruleLink(alert, className) {
      const link = document.createElement('a');
      link.className = className;
      link.href = alert.link;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      const more = document.createElement('span');
      more.className = 'visually-hidden';
      more.textContent = ` ${global.Drupal.t('(@rule, opens in a new tab)', {
        '@rule': alert.rule,
      })}`;
      link.append(
        global.Drupal.t('About this rule'),
        more,
        this.icon('external'),
      );
      return link;
    },

    /**
     * Counts findings by level.
     *
     * @param {Array<object>} findings
     *   The findings, each with its alert.
     *
     * @return {{error: number, warning: number, suggestion: number}}
     *   How many there are at each level. A level Vale does not have
     *   counts as a suggestion.
     */
    levelCounts(findings) {
      const counts = { error: 0, warning: 0, suggestion: 0 };
      findings.forEach((finding) => {
        const { level } = finding.alert;
        counts[level in counts ? level : 'suggestion'] += 1;
      });
      return counts;
    },

    /**
     * Sorts findings with the errors first, then the warnings.
     *
     * @param {Array<object>} findings
     *   The findings, each with its alert.
     *
     * @return {Array<object>}
     *   A sorted copy. Findings of one level keep their order.
     */
    byLevel(findings) {
      const rank = { error: 0, warning: 1 };
      const of = (finding) =>
        finding.alert.level in rank ? rank[finding.alert.level] : 2;
      return findings
        .map((finding, index) => ({ finding, index }))
        .sort((a, b) => of(a.finding) - of(b.finding) || a.index - b.index)
        .map((entry) => entry.finding);
    },

    /**
     * Says whether the list of findings should be open.
     *
     * It opens by itself only for an error. Once the author opens or
     * closes it, it stays as they left it.
     *
     * @param {{error: number}} counts
     *   The counts levelCounts() gave.
     * @param {boolean} chosen
     *   Whether the author opened or closed the list.
     * @param {boolean} open
     *   Whether the list is open now.
     *
     * @return {boolean}
     *   TRUE to have it open.
     */
    opens(counts, chosen, open) {
      return chosen ? open : counts.error > 0;
    },

    /**
     * Gives the code unit offsets of an alert in a value.
     *
     * Vale gives a 1-based line, and a 1-based inclusive span of code
     * points on it. JavaScript indexes strings by UTF-16 code unit.
     *
     * @param {string} text
     *   The value.
     * @param {number} line
     *   The line.
     * @param {Array<number>} span
     *   The first and last column.
     *
     * @return {Array<number>|null}
     *   The start and end, with the end excluded, or NULL when the alert
     *   is not in the value.
     */
    offsetsOf(text, line, span) {
      const lines = text.split('\n');
      if (line < 1 || line > lines.length) {
        return null;
      }
      let base = 0;
      for (let i = 0; i < line - 1; i++) {
        base += lines[i].length + 1;
      }
      const row = lines[line - 1];
      const start = unitIndex(row, span[0] - 1);
      const end = unitIndex(row, span[1]);
      if (start === null || end === null || start > end) {
        return null;
      }
      return [base + start, base + end];
    },

    /**
     * Gives the one edit that turns a value into another.
     *
     * @param {string} before
     *   The old value.
     * @param {string} after
     *   The new value.
     *
     * @return {{at: number, removed: number, inserted: string}|null}
     *   The edit: removed code units at "at" were replaced by inserted. NULL
     *   when the values are the same.
     */
    diff(before, after) {
      if (before === after) {
        return null;
      }
      let start = 0;
      const max = Math.min(before.length, after.length);
      while (
        start < max &&
        before.charCodeAt(start) === after.charCodeAt(start)
      ) {
        start += 1;
      }
      let tail = 0;
      while (
        tail < max - start &&
        before.charCodeAt(before.length - 1 - tail) ===
          after.charCodeAt(after.length - 1 - tail)
      ) {
        tail += 1;
      }
      return {
        at: start,
        removed: before.length - start - tail,
        inserted: after.slice(start, after.length - tail),
      };
    },

    /**
     * Moves a span across an edit.
     *
     * @param {number} start
     *   The start.
     * @param {number} end
     *   The end, excluded.
     * @param {{at: number, removed: number, inserted: string}} edit
     *   The edit.
     *
     * @return {Array<number>|null}
     *   The new start and end, or NULL when the edit touched the span.
     */
    shift(start, end, edit) {
      const editEnd = edit.at + edit.removed;
      const pure = edit.removed === 0 && edit.inserted.length > 0;
      if (end <= edit.at) {
        // Letters typed onto the end of a marked word change the word.
        if (pure && end === edit.at && wordChar.test(edit.inserted[0])) {
          return null;
        }
        return [start, end];
      }
      if (start >= editEnd) {
        if (
          pure &&
          start === editEnd &&
          wordChar.test(edit.inserted[edit.inserted.length - 1])
        ) {
          return null;
        }
        const by = edit.inserted.length - edit.removed;
        return [start + by, end + by];
      }
      return null;
    },

    /**
     * Moves each span from one value to another.
     *
     * A span the edit touched is dropped, since what it pointed at is gone.
     *
     * @param {Array<object>} spans
     *   Objects with start and end.
     * @param {string} before
     *   The old value.
     * @param {string} after
     *   The new value.
     *
     * @return {Array<object>}
     *   The spans that are left, moved.
     */
    remap(spans, before, after) {
      const edit = global.ValeText.diff(before, after);
      if (!edit) {
        return spans;
      }
      const out = [];
      spans.forEach((span) => {
        const moved = global.ValeText.shift(span.start, span.end, edit);
        if (moved) {
          out.push({ ...span, start: moved[0], end: moved[1] });
        }
      });
      return out;
    },

    /**
     * Gives the text of some HTML, and where each character came from.
     *
     * Tags and comments are dropped, and character references decoded.
     *
     * @param {string} html
     *   The HTML.
     *
     * @return {{text: string, at: Array<number>}}
     *   The text, and the offset in the HTML of each of its code units.
     */
    htmlText(html) {
      const chars = [];
      const at = [];
      let i = 0;
      while (i < html.length) {
        const c = html[i];
        if (c === '<' && html.startsWith('<!--', i)) {
          const end = html.indexOf('-->', i + 4);
          i = end < 0 ? html.length : end + 3;
        } else if (c === '<' && /[A-Za-z/!?]/.test(html[i + 1] || '')) {
          let quote = null;
          i += 1;
          while (i < html.length && (quote || html[i] !== '>')) {
            if (quote && html[i] === quote) {
              quote = null;
            } else if (!quote && (html[i] === '"' || html[i] === "'")) {
              quote = html[i];
            }
            i += 1;
          }
          i += 1;
        } else {
          const slice = html.slice(i, i + 40);
          const entity = c === '&' ? reference.exec(slice) : null;
          const decoded = entity ? decode(entity[0]) : c;
          for (let k = 0; k < decoded.length; k++) {
            chars.push(decoded[k]);
            at.push(i);
          }
          i += entity ? entity[0].length : 1;
        }
      }
      return { text: chars.join(''), at };
    },

    /**
     * Lines up the characters of two texts that differ only in white space.
     *
     * The text of an editor's HTML has white space between blocks that its
     * model does not, and the model may have text the HTML does not.
     *
     * @param {string} from
     *   The first text.
     * @param {string} to
     *   The second text.
     *
     * @return {Array<number>}
     *   For each code unit of the first text, its offset in the second, or
     *   -1 when it is not there.
     */
    align(from, to) {
      const map = [];
      let j = 0;
      for (let i = 0; i < from.length; i++) {
        const a = normal(from[i]);
        while (j < to.length && normal(to[j]) !== a && /\s/.test(to[j])) {
          j += 1;
        }
        if (j < to.length && normal(to[j]) === a) {
          map.push(j);
          j += 1;
        } else {
          map.push(-1);
        }
      }
      return map;
    },
  };
})(globalThis);
