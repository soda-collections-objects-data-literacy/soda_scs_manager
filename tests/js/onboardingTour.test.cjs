const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('onboarding outlines all clickable controls with the matching colours', () => {
  const selectors = {
    '.project__files .project__files__button[href]': [{ id: 'file' }],
    '.project__applications .application--card:not(.application--card--add) .application__core[href]': [{ id: 'tile1' }, { id: 'tile2' }],
    '.project__applications .application--card:not(.application--card--add) .application__details-link[href]': [{ id: 'details1', href: '/details/1' }, { id: 'details2', href: '/details/2' }],
    '.application__details-card--applications .application--card .application__core[href]': [{ id: 'subsystem1' }, { id: 'subsystem2' }],
  };
  const document = { querySelectorAll: (selector) => selectors[selector] || [] };
  const Drupal = { behaviors: {}, t: (text) => text };
  const context = { Drupal, drupalSettings: {}, once: () => [], document };
  const file = path.join(__dirname, '../../js/onboardingTour.js');
  const source = fs.readFileSync(file, 'utf8').replace(
    '  Drupal.behaviors.sodaScsOnboardingTour = {',
    '  globalThis.testSteps = buildSteps();\n  Drupal.behaviors.sodaScsOnboardingTour = {',
  );
  vm.runInNewContext(source, context);

  const accents = (index) => Array.from(context.testSteps[index].accents(),
    ({ element, tone }) => `${element.id}:${tone}`);

  assert.deepEqual(accents(5), ['file:blue']);
  assert.deepEqual(accents(6), ['tile1:blue', 'tile2:blue', 'details1:green', 'details2:green']);
  assert.deepEqual(accents(9), ['subsystem1:blue', 'subsystem2:blue']);
  const state = { urls: {} };
  context.testSteps[6].capture(state);
  assert.equal(state.urls.application, '/details/1');
});

test('onboarding swaps the click hints for a wait note while the demo is being set up', () => {
  const load = (selectors) => {
    const document = { querySelectorAll: (selector) => selectors[selector] || [] };
    const Drupal = { behaviors: {}, t: (text) => text };
    const context = { Drupal, drupalSettings: {}, once: () => [], document };
    const file = path.join(__dirname, '../../js/onboardingTour.js');
    const source = fs.readFileSync(file, 'utf8').replace(
      '  Drupal.behaviors.sodaScsOnboardingTour = {',
      '  globalThis.testSteps = buildSteps();\n  Drupal.behaviors.sodaScsOnboardingTour = {',
    );
    vm.runInNewContext(source, context);
    return context.testSteps;
  };
  const hints = (step) => Array.from(step.blocks
    .filter((block) => block.kind === 'hint' && (!block.when || block.when())), (block) => block.text);

  const pending = load({
    '.project__files .project__files__pending': [{ id: 'note' }],
    '.project__applications .application--card--pending': [{ id: 'card' }],
  });
  assert.deepEqual(hints(pending[5]), ['The file system opens once the demo project is ready.']);
  assert.deepEqual(hints(pending[6]), [
    'The tiles open your apps once the demo project is ready.',
    'Or click the details @button to get to the application details.',
  ]);

  const ready = load({});
  assert.deepEqual(hints(ready[5]), ['Click the @button to get to the file system.']);
  assert.deepEqual(hints(ready[6]), [
    'Jump right in by clicking on the @tile.',
    'Or click the details @button to get to the application details.',
  ]);
});
