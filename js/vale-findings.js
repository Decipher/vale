/**
 * @file
 * Lists the findings under a text area or text field while the author types.
 *
 * These elements cannot mark text in place, so the findings go in a table
 * under the field, in a section that is closed until the author opens it,
 * or the text has an error. Selecting a finding selects its text in the
 * field. A value that a text editor holds is left to the editor's own
 * plugin, and has no list.
 *
 * The markup is core's: a details element with the classes the admin theme
 * styles, and a table in it. So it looks as the rest of the form does.
 *
 * The findings are advice. The save still checks every value on the server.
 */

((Drupal, drupalSettings, once, ValeText) => {
  // How long typing must pause before the value is linted, in milliseconds.
  const DELAY = 800;

  // How long the findings must stay as they are before a screen reader is
  // told of them, in milliseconds.
  const ANNOUNCE_DELAY = 500;

  // The levels, with the worst first.
  const LEVELS = ['error', 'warning', 'suggestion'];

  /**
   * Gives the label of a level.
   *
   * @param {string} level
   *   The level.
   *
   * @return {string}
   *   The label.
   */
  function levelLabel(level) {
    if (level === 'error') {
      return Drupal.t('Error');
    }
    return level === 'warning' ? Drupal.t('Warning') : Drupal.t('Suggestion');
  }

  /**
   * Says how many findings a level has.
   *
   * @param {string} level
   *   The level.
   * @param {number} count
   *   How many.
   *
   * @return {string}
   *   Such as "2 errors".
   */
  function countLabel(level, count) {
    if (level === 'error') {
      return Drupal.formatPlural(count, '1 error', '@count errors');
    }
    return level === 'warning'
      ? Drupal.formatPlural(count, '1 warning', '@count warnings')
      : Drupal.formatPlural(count, '1 suggestion', '@count suggestions');
  }

  /**
   * Makes an element with a class and some text.
   *
   * @param {string} tag
   *   The element's name.
   * @param {string} className
   *   Its class.
   * @param {string} [text]
   *   Its text.
   *
   * @return {HTMLElement}
   *   The element.
   */
  function make(tag, className, text) {
    const element = document.createElement(tag);
    element.className = className;
    if (text !== undefined) {
      element.textContent = text;
    }
    return element;
  }

  /**
   * Tells whether a text editor holds a value.
   *
   * @param {HTMLElement} element
   *   The text area.
   *
   * @return {boolean}
   *   TRUE when an editor is attached, or the value's text format has one.
   */
  function hasEditor(element) {
    if (element.hasAttribute('data-ckeditor5-id')) {
      return true;
    }
    const format = ValeText.formatOf(element);
    const editors = drupalSettings.editor && drupalSettings.editor.formats;
    return Boolean(format && editors && editors[format]);
  }

  /**
   * The findings of one text area or text field.
   */
  class FindingsList {
    /**
     * Constructs a FindingsList.
     *
     * @param {HTMLTextAreaElement|HTMLInputElement} element
     *   The element that holds the value.
     */
    constructor(element) {
      this.element = element;
      this.value = JSON.parse(element.dataset.valeFindings);
      this.findings = [];
      this.text = element.value;
      this.checked = null;
      this.run = 0;
      // TRUE while the first check of the text has not answered.
      this.pending = false;
      // TRUE when the last check found nothing. Typing can move every
      // finding out of the text, which does not make the text clean.
      this.clean = false;
      // TRUE once the author has opened or closed the list.
      this.chosen = false;
      // What a screen reader was last told, and what it is told next.
      this.spoken = null;
      this.speaking = null;

      this.box = make('div', 'vale-findings js-vale-findings');
      this.box.hidden = true;
      // One line, in the place and style of a field's description, for a
      // text with no findings to list.
      this.status = make('div', 'vale-findings__status form-item__description');
      this.status.hidden = true;
      // Core's details markup, with the classes Claro styles it by.
      this.details = make('details', 'vale-findings__details claro-details');
      this.details.hidden = true;
      this.summary = make(
        'summary',
        'vale-findings__summary claro-details__summary',
      );
      // A click is also what the keyboard gives a summary.
      this.summary.addEventListener('click', () => {
        this.chosen = true;
      });
      // The table scrolls in this region, so the keyboard must reach it.
      this.region = make(
        'div',
        'vale-findings__region claro-details__wrapper details-wrapper',
      );
      this.region.tabIndex = 0;
      this.region.setAttribute('role', 'region');
      this.region.setAttribute(
        'aria-label',
        Drupal.t('Vale findings for @label', { '@label': this.label() }),
      );
      const table = make('table', 'vale-findings__table');
      const head = make('tr', 'vale-findings__head');
      [Drupal.t('Level'), Drupal.t('Text'), Drupal.t('Finding')].forEach(
        (title) => {
          const cell = make('th', 'vale-findings__heading', title);
          cell.scope = 'col';
          head.append(cell);
        },
      );
      const thead = document.createElement('thead');
      thead.append(head);
      this.items = document.createElement('tbody');
      table.append(thead, this.items);
      this.region.append(table);
      this.details.append(this.summary, this.region);
      this.box.append(this.status, this.details);
      (element.closest('.js-form-item') || element).after(this.box);

      this.schedule = Drupal.debounce(() => this.lint(), DELAY);
      element.addEventListener('input', () => {
        this.move();
        this.schedule();
      });
      const format = ValeText.formatElement(element);
      if (format) {
        format.addEventListener('change', () => this.schedule());
      }
      // A text editor marks the element it attaches to. The list goes when
      // an editor attaches, and comes back when it is detached.
      if (typeof MutationObserver === 'function') {
        new MutationObserver(() => this.lint()).observe(element, {
          attributes: true,
          attributeFilter: [
            'data-ckeditor5-id',
            'data-editor-active-text-format',
          ],
        });
      }
      this.lint();
    }

    /**
     * Gives the field's label, for a screen reader.
     *
     * @return {string}
     *   The label.
     */
    label() {
      const label = this.element.labels && this.element.labels[0];
      return (
        (label && label.textContent.trim()) ||
        this.element.getAttribute('aria-label') ||
        this.element.name
      );
    }

    /**
     * Keeps each finding at its text while the value changes.
     */
    move() {
      const text = this.element.value;
      this.findings = ValeText.remap(this.findings, this.text, text);
      this.text = text;
      this.render();
    }

    /**
     * Lints the value, as the save would.
     *
     * @return {Promise<void>}
     *   Resolves once the list shows the result.
     */
    async lint() {
      this.run += 1;
      const { run } = this;
      const text = this.element.value;
      if (hasEditor(this.element) || text.trim() === '') {
        this.findings = [];
        this.checked = null;
        this.pending = false;
        this.clean = false;
        this.text = text;
        this.render();
        return;
      }
      // Nothing is known about the text yet, so the field says it is
      // being checked.
      if (
        this.checked === null ||
        (this.findings.length === 0 && !this.clean)
      ) {
        this.pending = true;
        this.render();
      }
      let result;
      try {
        result = await Drupal.vale.lint({
          ...this.value,
          text,
          format: ValeText.formatOf(this.element),
        });
      } catch (e) {
        // The check failed, such as when the server was busy. The next
        // pause in typing tries again.
        result = { checked: false, alerts: [] };
      }
      // A later run, or more typing, makes this result stale.
      if (run !== this.run || text !== this.element.value) {
        return;
      }
      this.pending = false;
      this.checked = result.checked;
      this.text = text;
      // The text was not checked, not found clean. The list keeps the
      // findings it has, and says the text was not checked.
      if (!result.checked) {
        this.render();
        return;
      }
      this.findings = [];
      this.clean = result.alerts.length === 0;
      result.alerts.forEach((alert) => {
        const offsets = ValeText.offsetsOf(text, alert.line, [
          alert.start,
          alert.end,
        ]);
        if (offsets) {
          this.findings.push({ alert, start: offsets[0], end: offsets[1] });
        }
      });
      this.render();
    }

    /**
     * Selects a finding's text in the field.
     *
     * @param {object} finding
     *   The finding.
     */
    select(finding) {
      this.element.focus();
      this.element.setSelectionRange(finding.start, finding.end);
    }

    /**
     * Makes the row of a finding.
     *
     * @param {object} finding
     *   The finding.
     *
     * @return {HTMLTableRowElement}
     *   The row.
     */
    row(finding) {
      const { alert } = finding;
      const level = LEVELS.includes(alert.level) ? alert.level : 'suggestion';
      const row = make('tr', `vale-findings__row vale-findings__row--${level}`);
      // Core's classes for a row of a status table.
      if (level !== 'suggestion') {
        row.classList.add(`color-${level}`);
      }

      const label = make('span', 'vale-findings__level');
      label.append(ValeText.icon(level), levelLabel(level));
      const levelCell = make(
        'td',
        'vale-findings__cell vale-findings__cell--level',
      );
      levelCell.append(label);

      // The button shows the text. Its name also says what it does.
      const button = make('button', 'vale-findings__select link');
      button.type = 'button';
      button.append(
        make(
          'span',
          'visually-hidden',
          `${Drupal.t('Select this text in the field:')} `,
        ),
        this.text.slice(finding.start, finding.end),
      );
      button.addEventListener('click', () => this.select(finding));
      const textCell = make(
        'td',
        'vale-findings__cell vale-findings__cell--text',
      );
      textCell.append(button);

      const about = make('div', 'vale-findings__about');
      about.append(make('code', 'vale-findings__rule', alert.rule));
      if (alert.link) {
        about.append(' ', ValeText.ruleLink(alert, 'vale-findings__link'));
      }
      const findingCell = make(
        'td',
        'vale-findings__cell vale-findings__cell--finding',
      );
      findingCell.append(
        make('div', 'vale-findings__message', alert.message),
        about,
      );

      row.append(levelCell, textCell, findingCell);
      return row;
    }

    /**
     * Shows the findings.
     */
    render() {
      const counts = ValeText.levelCounts(this.findings);
      const total = this.findings.length;

      let status = '';
      let sign = '';
      if (this.pending) {
        status = Drupal.t('Vale is checking this text...');
        sign = 'checking';
      } else if (this.checked === false) {
        status = Drupal.t(
          'Vale could not check this text. It is still checked when you save.',
        );
        sign = 'warning';
      } else if (this.checked && total === 0 && this.clean) {
        status = Drupal.t('Vale: no findings.');
        sign = 'none';
      }
      if (this.status.textContent !== status) {
        this.status.replaceChildren(
          ...(status === ''
            ? []
            : [ValeText.icon(sign), make('span', '', status)]),
        );
      }
      this.status.hidden = status === '';
      this.details.hidden = total === 0;
      this.box.hidden = status === '' && total === 0;
      this.announce(counts, total);
      if (total === 0) {
        this.summary.replaceChildren();
        this.items.replaceChildren();
        return;
      }

      const parts = [
        make(
          'span',
          'vale-findings__total',
          Drupal.formatPlural(
            total,
            'Vale: 1 finding',
            'Vale: @count findings',
          ),
        ),
      ];
      LEVELS.filter((level) => counts[level] > 0).forEach((level) => {
        const count = make(
          'span',
          `vale-findings__count vale-findings__count--${level}`,
        );
        count.append(ValeText.icon(level), countLabel(level, counts[level]));
        // The comma is for a screen reader, which reads the counts as one
        // sentence. The gap between them shows it to the eye.
        parts.push(make('span', 'visually-hidden', ', '), count);
      });
      this.summary.replaceChildren(...parts);

      // The rows are made again, and stay scrolled where they were.
      const scrolled = this.region.scrollTop;
      this.items.replaceChildren(
        ...ValeText.byLevel(this.findings).map((finding) => this.row(finding)),
      );
      this.region.scrollTop = scrolled;
      this.details.open = ValeText.opens(
        counts,
        this.chosen,
        this.details.open,
      );
    }

    /**
     * Tells a screen reader of the findings, when they change.
     *
     * It waits for the findings to stay as they are for a moment, so that
     * typing is not talked over.
     *
     * @param {{error: number, warning: number, suggestion: number}} counts
     *   How many findings each level has.
     * @param {number} total
     *   How many findings there are.
     */
    announce(counts, total) {
      if (this.pending) {
        return;
      }
      const label = { '@label': this.label() };
      let next;
      window.clearTimeout(this.speaking);
      if (
        this.checked === null ||
        (total === 0 && this.checked && !this.clean)
      ) {
        // There is no text, or its findings are not known yet.
        return;
      }
      if (this.checked === false) {
        next = {
          key: 'unchecked',
          message: Drupal.t('Vale could not check the text in @label.', label),
        };
      } else if (total === 0) {
        next = {
          key: 'none',
          message: Drupal.t('Vale: no findings in @label', label),
        };
      } else {
        next = {
          key: LEVELS.map((level) => counts[level]).join(':'),
          message: Drupal.formatPlural(
            total,
            'Vale: 1 finding in @label',
            'Vale: @count findings in @label',
            label,
          ),
        };
      }
      this.speaking = window.setTimeout(() => {
        if (next.key === this.spoken) {
          return;
        }
        // A text that is clean when the form opens needs no word.
        const quiet = next.key === 'none' && this.spoken === null;
        this.spoken = next.key;
        if (!quiet) {
          Drupal.announce(next.message);
        }
      }, ANNOUNCE_DELAY);
    }
  }

  /**
   * Lists the findings under each text area and text field Vale checks.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Starts linting each field's value.
   */
  Drupal.behaviors.valeFindings = {
    attach(context) {
      once(
        'vale-findings',
        'textarea[data-vale-findings], input[data-vale-findings]',
        context,
      ).forEach((element) => {
        // eslint-disable-next-line no-new
        new FindingsList(element);
      });
    },
  };
})(Drupal, drupalSettings, once, window.ValeText);
