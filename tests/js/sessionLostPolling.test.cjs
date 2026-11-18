const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

/**
 * Polling after the session expired (#3639179).
 *
 * A health or notification poll answered with 401/403 means the session is
 * gone: the script must stop polling, a transport error must not.
 */

const readScript = (name) => fs.readFileSync(path.join(__dirname, '../../js', name), 'utf8');

/**
 * A chainable jQuery stand-in: every method returns the same object.
 */
function chain(dataMap = {}) {
  const target = function () {};
  const proxy = new Proxy(target, {
    get(_object, property) {
      if (typeof property === 'symbol') {
        return undefined;
      }
      if (property === 'length') {
        return 1;
      }
      if (property === 'data') {
        return (key) => dataMap[key];
      }
      if (property === 'text' || property === 'html') {
        return (value) => (value === undefined ? '' : proxy);
      }
      return () => proxy;
    },
    apply() {
      return proxy;
    },
  });
  return proxy;
}

/**
 * Builds a sandbox with fake timers, a fake jQuery and the health helpers.
 */
function buildSandbox({ dataMap = {}, drupalSettings = {} } = {}) {
  const requests = [];
  const intervals = new Map();
  let nextIntervalId = 1;

  const $ = () => chain(dataMap);
  $.ajax = (options) => {
    const request = { options, doneCallbacks: [], failCallbacks: [] };
    const promise = {
      done(callback) {
        request.doneCallbacks.push(callback);
        return promise;
      },
      then(callback) {
        request.doneCallbacks.push(callback);
        return promise;
      },
      fail(callback) {
        request.failCallbacks.push(callback);
        return promise;
      },
    };
    requests.push(request);
    return promise;
  };

  const Drupal = {
    behaviors: {},
    t: (text) => text,
    url: (text) => `/${text}`,
    announce: () => {},
  };
  const context = {
    $,
    jQuery: $,
    Drupal,
    drupalSettings,
    once: (_id, _selector, _context) => ['element'],
    console,
    document: { hidden: false },
    setInterval: (callback, delay) => {
      const id = nextIntervalId++;
      intervals.set(id, { callback, delay });
      return id;
    },
    clearInterval: (id) => {
      intervals.delete(id);
    },
    setTimeout: () => 0,
    clearTimeout: () => {},
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(readScript('healthStatus.js'), context);

  return {
    context,
    Drupal,
    requests,
    intervals,
    load(name) {
      vm.runInContext(readScript(name), context);
    },
    /** Intervals that poll (everything except the 500 ms and 1 s dot animations). */
    pollIntervals() {
      return [...intervals.values()].filter(({ delay }) => delay >= 3000);
    },
  };
}

test('isSessionLost is true for 401 and 403 only', () => {
  const { Drupal } = buildSandbox();
  assert.equal(Drupal.sodaScsHealth.isSessionLost({ status: 401 }), true);
  assert.equal(Drupal.sodaScsHealth.isSessionLost({ status: 403 }), true);
  assert.equal(Drupal.sodaScsHealth.isSessionLost({ status: 0 }), false);
  assert.equal(Drupal.sodaScsHealth.isSessionLost({ status: 404 }), false);
  assert.equal(Drupal.sodaScsHealth.isSessionLost({ status: 500 }), false);
  assert.equal(Drupal.sodaScsHealth.isSessionLost({ status: 200 }), false);
});

test('isSessionLost is false without a response object', () => {
  const { Drupal } = buildSandbox();
  assert.equal(Drupal.sodaScsHealth.isSessionLost(undefined), false);
  assert.equal(Drupal.sodaScsHealth.isSessionLost(null), false);
});

test('sessionLostMessage asks to reload the page', () => {
  const { Drupal } = buildSandbox();
  assert.equal(
    Drupal.sodaScsHealth.sessionLostMessage(),
    'Your session has expired. Reload the page to log in again.',
  );
});

/**
 * Attaches the behavior and returns the sandbox, with the first request pending.
 */
function attach(scriptName, behaviorName, sandboxOptions) {
  const sandbox = buildSandbox(sandboxOptions);
  sandbox.load(scriptName);
  sandbox.Drupal.behaviors[behaviorName].attach({}, {});
  return sandbox;
}

const POLLERS = [
  {
    script: 'componentListHealthStatus.js',
    behavior: 'componentListHealthStatus',
    options: { dataMap: { 'component-id': 7, 'component-bundle': 'soda_scs_wisski_component' } },
  },
  {
    script: 'updateHealthStatus.js',
    behavior: 'updateHealthStatus',
    options: { drupalSettings: { entityInfo: { healthUrl: '/soda-scs-manager/health/component/7' } } },
  },
  {
    script: 'dashboardHealthStatus.js',
    behavior: 'dashboardHealthStatus',
    options: { dataMap: { 'entity-id': 7, 'entity-type': 'soda_scs_component' } },
  },
];

for (const { script, behavior, options } of POLLERS) {
  test(`${script} polls the health URL on an interval after attach`, () => {
    const sandbox = attach(script, behavior, options);
    assert.equal(sandbox.requests.length, 1);
    assert.match(sandbox.requests[0].options.url, /health\/component\/7$/);
    assert.equal(sandbox.pollIntervals().length, 1);
  });

  for (const status of [401, 403]) {
    test(`${script} stops polling when the poll fails with ${status}`, () => {
      const sandbox = attach(script, behavior, options);
      assert.equal(sandbox.pollIntervals().length, 1);
      for (const callback of sandbox.requests[0].failCallbacks) {
        callback({ status });
      }
      assert.equal(sandbox.pollIntervals().length, 0);
    });
  }

  for (const status of [0, 500, 502]) {
    test(`${script} keeps polling when the poll fails with ${status}`, () => {
      const sandbox = attach(script, behavior, options);
      for (const callback of sandbox.requests[0].failCallbacks) {
        callback({ status });
      }
      assert.equal(sandbox.pollIntervals().length, 1);
    });
  }

  test(`${script} keeps polling after a successful poll`, () => {
    const sandbox = attach(script, behavior, options);
    for (const callback of sandbox.requests[0].doneCallbacks) {
      callback({ status: { status: 'running' } });
    }
    assert.equal(sandbox.pollIntervals().length, 1);
  });
}

/**
 * Loads notificationBadge.js with fake fetch, timers and event targets.
 */
function buildBadgeSandbox(responder) {
  const listeners = { document: {}, window: {} };
  const timers = new Map();
  const fetches = [];
  const rendered = [];
  const badge = {
    textContent: '',
    dataset: {},
    hidden: true,
  };
  const menuItem = {
    querySelector: () => badge,
    classList: { toggle: (name, on) => rendered.push([name, on]) },
  };
  const document = {
    hidden: false,
    querySelectorAll: () => [menuItem],
    addEventListener: (type, callback) => {
      listeners.document[type] = callback;
    },
    removeEventListener: (type, callback) => {
      if (listeners.document[type] === callback) {
        delete listeners.document[type];
      }
    },
  };
  const window = {
    setInterval: (callback, delay) => {
      timers.set(1, { callback, delay });
      return 1;
    },
    clearInterval: (id) => {
      timers.delete(id);
    },
    addEventListener: (type, callback) => {
      listeners.window[type] = callback;
    },
    removeEventListener: (type, callback) => {
      if (listeners.window[type] === callback) {
        delete listeners.window[type];
      }
    },
  };
  const Drupal = { behaviors: {} };
  const context = {
    Drupal,
    once: () => ['html'],
    document,
    window,
    fetch: async (url, options) => {
      fetches.push({ url, options });
      return responder();
    },
  };
  vm.runInNewContext(readScript('notificationBadge.js'), context);
  Drupal.behaviors.sodaScsNotificationBadge.attach({});
  return { listeners, timers, fetches, badge, document };
}

test('notificationBadge polls every 30 seconds and refreshes on focus and visibility', () => {
  const sandbox = buildBadgeSandbox(() => ({ status: 200, ok: true, json: async () => ({ count: 2 }) }));
  assert.equal(sandbox.timers.get(1).delay, 30000);
  assert.equal(typeof sandbox.listeners.window.focus, 'function');
  assert.equal(typeof sandbox.listeners.document.visibilitychange, 'function');
});

for (const status of [401, 403]) {
  test(`notificationBadge stops polling and listeners after a ${status}`, async () => {
    const sandbox = buildBadgeSandbox(() => ({ status, ok: false, json: async () => ({}) }));
    await sandbox.timers.get(1).callback();
    assert.equal(sandbox.fetches.length, 1);
    assert.equal(sandbox.timers.size, 0);
    assert.equal(sandbox.listeners.window.focus, undefined);
    assert.equal(sandbox.listeners.document.visibilitychange, undefined);
  });

  test(`notificationBadge sends no further request after a ${status}`, async () => {
    const sandbox = buildBadgeSandbox(() => ({ status, ok: false, json: async () => ({}) }));
    const refresh = sandbox.timers.get(1).callback;
    await refresh();
    await refresh();
    assert.equal(sandbox.fetches.length, 1);
  });
}

test('notificationBadge keeps polling after a server error', async () => {
  const sandbox = buildBadgeSandbox(() => ({ status: 500, ok: false, json: async () => ({}) }));
  const refresh = sandbox.timers.get(1).callback;
  await refresh();
  await refresh();
  assert.equal(sandbox.fetches.length, 2);
  assert.equal(sandbox.timers.size, 1);
  assert.equal(typeof sandbox.listeners.window.focus, 'function');
});

test('notificationBadge keeps polling after a network error', async () => {
  const sandbox = buildBadgeSandbox(() => {
    throw new TypeError('network down');
  });
  const refresh = sandbox.timers.get(1).callback;
  await refresh();
  await refresh();
  assert.equal(sandbox.fetches.length, 2);
  assert.equal(sandbox.timers.size, 1);
});

test('notificationBadge renders the count of a successful poll', async () => {
  const sandbox = buildBadgeSandbox(() => ({ status: 200, ok: true, json: async () => ({ count: 120 }) }));
  await sandbox.timers.get(1).callback();
  assert.equal(sandbox.badge.textContent, '99+');
  assert.equal(sandbox.badge.dataset.notificationCount, '120');
  assert.equal(sandbox.badge.hidden, false);
});

test('notificationBadge skips the request while the tab is hidden', async () => {
  const sandbox = buildBadgeSandbox(() => ({ status: 200, ok: true, json: async () => ({ count: 1 }) }));
  sandbox.document.hidden = true;
  await sandbox.timers.get(1).callback();
  assert.equal(sandbox.fetches.length, 0);
  assert.equal(sandbox.timers.size, 1);
});
