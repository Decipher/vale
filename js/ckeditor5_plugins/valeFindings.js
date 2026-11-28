/**
 * @file
 * A CKEditor 5 plugin that marks each finding in the text while it is typed.
 *
 * Hovering a mark, or moving the caret into one, shows the rule, the message
 * and the rule's link. The toolbar button hides the marks. The plugin works
 * only in a field Vale checks, whose form display shows findings: the field's
 * text area then has a data-vale-findings attribute.
 *
 * It is plain JavaScript, so it needs no build step. It takes the CKEditor 5
 * classes from the DLL that Drupal core ships, as a built plugin does.
 *
 * The marks are advice. The save still checks every value on the server.
 */

((Drupal, CKEditor5, ValeText) => {
  const { Plugin } = CKEditor5.dll('./src/core.js');
  const { ButtonView } = CKEditor5.dll('./src/ui.js');

  // How long typing must pause before the value is linted, in milliseconds.
  const DELAY = 800;

  // The toolbar icon, as in icons/vale-findings.svg.
  const ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M3.5 2h2.4L10 12.2 14.1 2h2.4L11 15H9z"/><path d="M2 17.5c1.3 0 1.3-1 2.7-1s1.3 1 2.6 1 1.3-1 2.7-1 1.3 1 2.6 1 1.3-1 2.7-1 1.4 1 2.7 1V19c-1.3 0-1.3-1-2.7-1s-1.3 1-2.7 1-1.3-1-2.6-1-1.3 1-2.7 1-1.3-1-2.6-1-1.4 1-2.7 1z"/></svg>';

  /**
   * Gives the text in a model range.
   *
   * @param {module:engine/model/range~Range} range
   *   The range.
   *
   * @return {string}
   *   The text.
   */
  function rangeText(range) {
    return Array.from(range.getItems())
      .filter((item) => item.is('$textProxy'))
      .map((item) => item.data)
      .join('');
  }

  /**
   * Marks Vale's findings in the editor.
   */
  class ValeFindings extends Plugin {
    /**
     * {@inheritdoc}
     */
    static get pluginName() {
      return 'ValeFindings';
    }

    /**
     * {@inheritdoc}
     */
    init() {
      const { editor } = this;
      const source = editor.sourceElement;
      this.value =
        source && source.dataset.valeFindings
          ? JSON.parse(source.dataset.valeFindings)
          : null;
      // The alert and text of each marker, by the marker's name.
      this.found = new Map();
      this.draws = 0;
      this.run = 0;
      this.version = 0;
      this.card = null;
      this.byCaret = false;
      this.set('shown', true);
      // FALSE when the last check failed. The marks are then from an
      // earlier check, and the toolbar button says so.
      this.set('checked', true);
      // TRUE until the first check answers, so the toolbar button can say
      // the text is still being checked.
      this.set('pending', false);

      editor.ui.componentFactory.add('valeFindings', (locale) => {
        const button = new ButtonView(locale);
        button.set({
          label: Drupal.t('Vale findings'),
          icon: ICON,
          tooltip: this.value
            ? Drupal.t('Show or hide Vale findings')
            : Drupal.t('Vale does not check this field'),
          isToggleable: true,
          isEnabled: Boolean(this.value),
        });
        button.bind('isOn').to(this, 'shown');
        if (this.value) {
          button
            .bind('tooltip')
            .to(this, 'checked', this, 'pending', (checked, pending) => {
              if (pending) {
                return Drupal.t('Vale is checking this text');
              }
              return checked
                ? Drupal.t('Show or hide Vale findings')
                : Drupal.t('Vale could not check this text');
            });
          button
            .bind('class')
            .to(this, 'checked', this, 'pending', (checked, pending) => {
              if (pending) {
                return 'vale-findings-button vale-findings-button--checking';
              }
              return checked
                ? 'vale-findings-button'
                : 'vale-findings-button vale-findings-button--unchecked';
            });
        }
        this.listenTo(button, 'execute', () => {
          this.shown = !this.shown;
        });
        return button;
      });

      if (!this.value) {
        return;
      }

      editor.conversion.for('editingDowncast').markerToHighlight({
        model: 'vale',
        view: (data) => {
          const found = this.found.get(data.markerName);
          const level = found ? found.alert.level : 'suggestion';
          return {
            classes: ['vale-mark', `vale-mark--${level}`],
            attributes: { 'data-vale-marker': data.markerName },
          };
        },
      });

      this.schedule = Drupal.debounce(() => this.lint(), DELAY);
      this.listenTo(editor.model.document, 'change:data', () => {
        this.version += 1;
        this.prune();
        this.schedule();
      });
      this.on('change:shown', () => {
        if (this.shown) {
          this.lint();
        } else {
          this.clear();
        }
      });
      this.listenTo(editor.editing.view, 'render', () => this.caret());
      editor.on('ready', () => {
        const root = editor.editing.view.getDomRoot();
        this.card = new Drupal.vale.Card(
          root ? root.closest('main, [role="main"]') : null,
        );
        this.onOver = (event) => {
          const mark = event.target.closest('[data-vale-marker]');
          if (mark) {
            this.byCaret = false;
            this.open(mark);
          }
        };
        this.onOut = (event) => {
          if (event.target.closest('[data-vale-marker]')) {
            this.card.hideSoon();
          }
        };
        root.addEventListener('mouseover', this.onOver);
        root.addEventListener('mouseout', this.onOut);
        this.pending = true;
        this.lint().finally(() => {
          this.pending = false;
        });
      });
    }

    /**
     * Gives the names of the markers this plugin added.
     *
     * @return {Array<string>}
     *   The names.
     */
    markers() {
      return Array.from(this.editor.model.markers.getMarkersGroup('vale')).map(
        (marker) => marker.name,
      );
    }

    /**
     * Shows the card of a mark.
     *
     * @param {HTMLElement} mark
     *   The mark's element.
     */
    open(mark) {
      const found = this.found.get(mark.dataset.valeMarker);
      if (found && this.card) {
        this.card.show(found.alert, mark.getBoundingClientRect());
      }
    }

    /**
     * Shows the card of the mark the caret is in, for keyboard users.
     */
    caret() {
      if (!this.card) {
        return;
      }
      const { selection } = this.editor.model.document;
      const marker = Array.from(selection.markers).find((item) =>
        item.name.startsWith('vale:'),
      );
      const root = this.editor.editing.view.getDomRoot();
      const mark =
        marker && selection.isCollapsed && root
          ? root.querySelector(
              `[data-vale-marker="${CSS.escape(marker.name)}"]`,
            )
          : null;
      if (mark) {
        this.byCaret = true;
        this.open(mark);
      } else if (this.byCaret) {
        this.byCaret = false;
        this.card.hide();
      }
    }

    /**
     * Lints the editor's data, as the save would.
     *
     * @return {Promise<void>}
     *   Resolves once the marks show the result.
     */
    async lint() {
      if (!this.shown) {
        return;
      }
      const { editor } = this;
      this.run += 1;
      const { run, version } = this;
      const text = editor.getData();
      let result;
      try {
        result = await Drupal.vale.lint({
          ...this.value,
          text,
          format: ValeText.formatOf(editor.sourceElement),
        });
      } catch (e) {
        // The check failed, such as when the server was busy. The next
        // pause in typing tries again.
        result = { checked: false, alerts: [] };
      }
      // A later run, or more typing, makes this result stale.
      if (run !== this.run || version !== this.version || !this.shown) {
        return;
      }
      this.checked = result.checked !== false;
      // The text was not checked, not found clean. The marks stay as they
      // are, instead of being cleared.
      if (!this.checked) {
        return;
      }
      this.draw(result.alerts, text);
    }

    /**
     * Replaces the marks with those for some alerts.
     *
     * Vale gives where an alert is in the HTML. The model holds the text
     * without the tags, so each alert is found in the text of the HTML,
     * and then in the model's text.
     *
     * @param {Array<object>} alerts
     *   The alerts.
     * @param {string} html
     *   The data the alerts are for.
     */
    draw(alerts, html) {
      const { model } = this.editor;
      const { text, at } = ValeText.htmlText(html);
      const runs = [];
      let plain = '';
      Array.from(model.createRangeIn(model.document.getRoot()).getItems())
        .filter((item) => item.is('$textProxy'))
        .forEach((item) => {
          runs.push({ offset: plain.length, item });
          plain += item.data;
        });
      const map = ValeText.align(text, plain);
      const position = (offset, end) => {
        const found = runs.find(
          (r) =>
            r.offset <= offset &&
            (end
              ? offset <= r.offset + r.item.data.length
              : offset < r.offset + r.item.data.length),
        );
        return found
          ? model.createPositionAt(
              found.item.parent,
              found.item.startOffset + offset - found.offset,
            )
          : null;
      };

      this.draws += 1;
      const old = this.markers();
      const added = [];
      alerts.forEach((alert, n) => {
        const source = ValeText.offsetsOf(html, alert.line, [
          alert.start,
          alert.end,
        ]);
        if (!source) {
          return;
        }
        const inside = [];
        at.forEach((offset, i) => {
          if (offset >= source[0] && offset < source[1] && map[i] >= 0) {
            inside.push(i);
          }
        });
        if (inside.length === 0) {
          return;
        }
        const first = inside[0];
        const last = inside[inside.length - 1];
        const start = position(map[first], false);
        const end = position(map[last] + 1, true);
        const marked = plain.slice(map[first], map[last] + 1);
        // Skip an alert whose text the model does not hold as it is.
        if (
          !start ||
          !end ||
          marked.replace(/\s+/g, ' ') !==
            text.slice(first, last + 1).replace(/\s+/g, ' ')
        ) {
          return;
        }
        const name = `vale:${this.draws}:${n}`;
        this.found.set(name, { alert, text: marked });
        added.push({ name, range: model.createRange(start, end) });
      });

      model.change((writer) => {
        old.forEach((name) => writer.removeMarker(name));
        added.forEach(({ name, range }) =>
          writer.addMarker(name, {
            range,
            usingOperation: false,
            affectsData: false,
          }),
        );
      });
      this.forget();
    }

    /**
     * Drops the alerts of markers that are gone.
     */
    forget() {
      const names = this.markers();
      Array.from(this.found.keys()).forEach((name) => {
        if (!names.includes(name)) {
          this.found.delete(name);
        }
      });
      // The card goes with the mark it explains.
      if (
        this.card &&
        this.card.isOpen() &&
        !Array.from(this.found.values()).some(
          (found) => found.alert === this.card.alert,
        )
      ) {
        this.byCaret = false;
        this.card.hide();
      }
    }

    /**
     * Removes each mark whose text an edit changed.
     *
     * What it pointed at is gone. The next lint marks the text again.
     */
    prune() {
      const { model } = this.editor;
      const changed = this.markers().filter((name) => {
        const marker = model.markers.get(name);
        const found = this.found.get(name);
        const range = marker.getRange();
        // Deleted text goes to the editor's graveyard, and its mark with it.
        return (
          !found ||
          range.root.rootName === '$graveyard' ||
          rangeText(range) !== found.text
        );
      });
      if (changed.length > 0) {
        model.change((writer) => {
          changed.forEach((name) => writer.removeMarker(name));
        });
      }
      // The editor drops a mark itself when all its text is deleted.
      this.forget();
    }

    /**
     * Removes every mark.
     */
    clear() {
      const names = this.markers();
      this.editor.model.change((writer) => {
        names.forEach((name) => writer.removeMarker(name));
      });
      this.forget();
      if (this.card) {
        this.card.hide();
      }
    }

    /**
     * {@inheritdoc}
     */
    destroy() {
      const root = this.editor.editing.view.getDomRoot();
      if (root && this.onOver) {
        root.removeEventListener('mouseover', this.onOver);
        root.removeEventListener('mouseout', this.onOut);
      }
      if (this.card) {
        this.card.destroy();
      }
      super.destroy();
    }
  }

  // Drupal finds the plugin as valeFindings.ValeFindings.
  CKEditor5.valeFindings = { ValeFindings };
})(Drupal, CKEditor5, window.ValeText);
