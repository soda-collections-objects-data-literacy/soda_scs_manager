/**
 * @file
 * Reads the error messages from a form page that was submitted via fetch.
 *
 * The project wizard and the add-application popup post Drupal forms in the
 * background and must show the server's reason when the form comes back with
 * errors. The theme (stable9) renders one message as plain text inside
 * [role="alert"] and several as a list, without a "messages--error" class.
 */
(function (Drupal) {
  'use strict';

  var MESSAGE_SELECTOR = '.form-item--error-message, .messages--error, [data-drupal-messages] [role="alert"]';

  function normalize(text) {
    return (text || '').replace(/\s+/g, ' ').trim();
  }

  function messageTexts(element) {
    // PHP errors shown to administrators are not a reason for the user.
    if (element.querySelector('.error-with-backtrace')) {
      return [];
    }
    var items = element.querySelectorAll('li');
    if (items.length > 0) {
      return Array.prototype.map.call(items, function (item) {
        return normalize(item.textContent);
      });
    }
    var copy = element.cloneNode(true);
    copy.querySelectorAll('.visually-hidden').forEach(function (hidden) {
      hidden.remove();
    });
    return [normalize(copy.textContent)];
  }

  Drupal.sodaScsManager = Drupal.sodaScsManager || {};

  /**
   * Collects the distinct error messages of a parsed form page.
   *
   * @param {Document} doc
   *   The page returned by the form submission.
   *
   * @return {string[]}
   *   The messages in document order, without duplicates.
   */
  Drupal.sodaScsManager.collectFormErrors = function (doc) {
    var texts = [];
    doc.querySelectorAll(MESSAGE_SELECTOR).forEach(function (element) {
      messageTexts(element).forEach(function (text) {
        if (text && texts.indexOf(text) === -1) {
          texts.push(text);
        }
      });
    });
    return texts;
  };

})(Drupal);
