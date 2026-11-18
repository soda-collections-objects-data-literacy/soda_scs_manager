const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const SELECTOR = '.form-item--error-message, .messages--error, [data-drupal-messages] [role="alert"]';

/**
 * A minimal element: text, optional list items, optional backtrace, hidden parts.
 */
function element({ text = '', items = [], backtrace = false, hidden = [] } = {}) {
  const el = {
    textContent: text,
    querySelector: (selector) => (selector === '.error-with-backtrace' && backtrace ? {} : null),
    querySelectorAll: (selector) => {
      if (selector === 'li') {
        return items.map((item) => ({ textContent: item }));
      }
      return [];
    },
    cloneNode: () => {
      let remaining = text;
      const copy = {
        get textContent() {
          return remaining;
        },
        querySelectorAll: (selector) => (selector === '.visually-hidden'
          ? hidden.map((hiddenText) => ({ remove: () => { remaining = remaining.replace(hiddenText, ''); } }))
          : []),
      };
      return copy;
    },
  };
  return el;
}

function collect(elements) {
  const doc = {
    querySelectorAll: (selector) => {
      assert.equal(selector, SELECTOR);
      return elements;
    },
  };
  const Drupal = {};
  vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '../../js/formErrorMessages.js'), 'utf8'),
    { Drupal },
  );
  // Copy into a host array: the result comes from another vm realm.
  return Array.from(Drupal.sodaScsManager.collectFormErrors(doc));
}

test('a single plain-text message is returned with collapsed whitespace', () => {
  assert.deepEqual(collect([element({ text: '  An application\n   with this name exists.  ' })]),
    ['An application with this name exists.']);
});

test('several messages in a list are returned one by one', () => {
  assert.deepEqual(collect([element({ text: 'ignored', items: [' First ', 'Second'] })]),
    ['First', 'Second']);
});

test('visually hidden labels such as "Error message" are dropped', () => {
  assert.deepEqual(collect([element({ text: 'Error message Name is taken.', hidden: ['Error message'] })]),
    ['Name is taken.']);
});

test('PHP errors with a backtrace are not shown as a reason', () => {
  assert.deepEqual(collect([element({ text: 'Fatal', backtrace: true }), element({ text: 'Name is taken.' })]),
    ['Name is taken.']);
});

test('duplicates are removed and the document order is kept', () => {
  assert.deepEqual(collect([
    element({ text: 'B' }),
    element({ text: 'A' }),
    element({ text: 'B' }),
    element({ items: ['A', 'C'] }),
  ]), ['B', 'A', 'C']);
});

test('empty messages are skipped and an empty page returns an empty list', () => {
  assert.deepEqual(collect([element({ text: '   ' }), element({ items: ['', '  '] })]), []);
  assert.deepEqual(collect([]), []);
});

test('the helper keeps an existing Drupal.sodaScsManager namespace', () => {
  const Drupal = { sodaScsManager: { other: 1 } };
  vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '../../js/formErrorMessages.js'), 'utf8'),
    { Drupal },
  );
  assert.equal(Drupal.sodaScsManager.other, 1);
  assert.equal(typeof Drupal.sodaScsManager.collectFormErrors, 'function');
});
