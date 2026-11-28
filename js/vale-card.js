/**
 * @file
 * The card that explains a finding, under its mark in a text editor.
 *
 * Adapted from the popover in lib/overlay/overlay.ts of the Vale browser
 * extension, https://github.com/vale-cli/vale-web, at commit
 * 4a0732a0cc8044e996936b638896b8261d9e6579. Changed to plain JavaScript,
 * styled by css/vale-findings.css, and cut down to the rule, the message and
 * the rule's link: the marks are advice, so the card offers no fixes.
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

((Drupal, ValeText) => {
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
   * A card that shows one finding at a time.
   */
  class Card {
    /**
     * Constructs a Card, and adds it to the page.
     *
     * @param {HTMLElement} [container]
     *   The element to add it to, such as the page's main landmark, so a
     *   screen reader finds the card in the same part of the page as the
     *   editor. The body when not given.
     */
    constructor(container) {
      this.alert = null;
      this.timer = null;
      this.pop = document.createElement('div');
      this.pop.className = 'vale-card';
      this.pop.hidden = true;
      this.pop.setAttribute('role', 'tooltip');
      // Clicking the card must not take the focus from the editor.
      this.pop.addEventListener('mousedown', (event) => event.preventDefault());
      this.pop.addEventListener('mouseenter', () => this.cancelHide());
      this.pop.addEventListener('mouseleave', () => this.hideSoon());
      (container || document.body).append(this.pop);
    }

    /**
     * Shows a finding under its mark.
     *
     * @param {object} alert
     *   The alert, in the shape meta.vale gives it.
     * @param {DOMRect} rect
     *   Where the mark is in the viewport.
     */
    show(alert, rect) {
      this.cancelHide();
      if (this.alert === alert && !this.pop.hidden) {
        return;
      }
      this.alert = alert;
      this.pop.replaceChildren();
      this.pop.className = `vale-card vale-card--${alert.level}`;

      // The same icon, words and layout as a row of the list under a
      // text area.
      const head = document.createElement('div');
      head.className = 'vale-card__head';
      head.append(ValeText.icon(alert.level), levelLabel(alert.level));

      const message = document.createElement('div');
      message.className = 'vale-card__message';
      message.textContent = alert.message;

      const about = document.createElement('div');
      about.className = 'vale-card__about';
      const rule = document.createElement('code');
      rule.className = 'vale-card__rule';
      rule.textContent = alert.rule;
      about.append(rule);
      if (alert.link) {
        about.append(' ', ValeText.ruleLink(alert, 'vale-card__link'));
      }
      this.pop.append(head, message, about);
      this.place(rect);
    }

    /**
     * Puts the card under the mark, or above it when there is no room.
     *
     * @param {DOMRect} rect
     *   Where the mark is in the viewport.
     */
    place(rect) {
      this.pop.hidden = false;
      const width = this.pop.offsetWidth;
      const height = this.pop.offsetHeight;
      const left = Math.max(
        8,
        Math.min(rect.left, window.innerWidth - width - 8),
      );
      const below =
        rect.bottom + 6 + height <= window.innerHeight ||
        rect.top - 6 - height < 0;
      const top = below ? rect.bottom + 6 : rect.top - 6 - height;
      // The card is placed from the element its position is worked out
      // from, which is the page unless an element around it is positioned.
      const parent = this.pop.offsetParent;
      const paged =
        !parent ||
        parent === document.body ||
        parent === document.documentElement;
      const base = paged
        ? { left: -window.scrollX, top: -window.scrollY }
        : parent.getBoundingClientRect();
      const scrolled = paged
        ? { left: 0, top: 0 }
        : { left: parent.scrollLeft, top: parent.scrollTop };
      this.pop.style.left = `${left - base.left + scrolled.left}px`;
      this.pop.style.top = `${top - base.top + scrolled.top}px`;
    }

    /**
     * Tells whether the card is showing.
     *
     * @return {boolean}
     *   TRUE when it is.
     */
    isOpen() {
      return !this.pop.hidden;
    }

    /**
     * Hides the card after a moment, so the pointer can move onto it.
     */
    hideSoon() {
      this.cancelHide();
      this.timer = window.setTimeout(() => this.hide(), 250);
    }

    /**
     * Keeps the card open.
     */
    cancelHide() {
      if (this.timer) {
        window.clearTimeout(this.timer);
      }
      this.timer = null;
    }

    /**
     * Hides the card.
     */
    hide() {
      this.cancelHide();
      this.pop.hidden = true;
      this.alert = null;
    }

    /**
     * Removes the card from the page.
     */
    destroy() {
      this.hide();
      this.pop.remove();
    }
  }

  Drupal.vale = Drupal.vale || {};

  /**
   * The card a text editor plugin shows a finding in.
   *
   * @type {Function}
   */
  Drupal.vale.Card = Card;
})(Drupal, window.ValeText);
