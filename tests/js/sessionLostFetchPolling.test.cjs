const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

/**
 * Fetch-based pollers after the session expired (#3639179).
 *
 * They mark their requests as script requests (X-Requested-With), so the
 * login route answers with a 403 instead of a redirect to the login
 * provider, and they stop polling on 401/403.
 */

const readScript = (name) => fs.readFileSync(path.join(__dirname, '../../js', name), 'utf8');

/**
 * Builds a sandbox with fake timers, a scripted fetch and one element.
 */
function buildSandbox(element, statuses) {
  const requests = [];
  const intervals = new Map();
  const listeners = new Map();
  let nextIntervalId = 1;

  const fetch = async (url, options) => {
    requests.push({ url, options });
    const status = statuses.length > 1 ? statuses.shift() : statuses[0];
    return {
      status,
      ok: status >= 200 && status < 300,
      json: async () => ({ isBusy: true, apps: [], createdCount: 0 }),
      text: async () => '<div></div>',
    };
  };

  const document = {
    hidden: false,
    documentElement: { scrollHeight: 0 },
    querySelector: () => null,
    addEventListener(type, callback) {
      listeners.set(type, callback);
    },
    removeEventListener(type, callback) {
      if (listeners.get(type) === callback) {
        listeners.delete(type);
      }
    },
    createElement: () => ({}),
    contains: () => false,
  };

  const sandbox = {
    console: { warn() {} },
    document,
    fetch,
    once: (_id, _selector) => [element],
    Drupal: {
      behaviors: {},
      t: (text) => text,
      attachBehaviors() {},
    },
    DOMParser: class {
      parseFromString() {
        return { querySelector: () => null };
      }
    },
    window: {
      location: { hash: '', href: 'https://manager.test/project/1', reload() {} },
      scrollY: 0,
      innerHeight: 0,
      setInterval(callback) {
        const id = nextIntervalId++;
        intervals.set(id, callback);
        return id;
      },
      clearInterval(id) {
        intervals.delete(id);
      },
    },
  };
  sandbox.window.document = document;
  vm.createContext(sandbox);

  return {
    sandbox,
    requests,
    intervals,
    listeners,
    async tick() {
      for (const callback of [...intervals.values()]) {
        await callback();
      }
      await new Promise((resolve) => setImmediate(resolve));
    },
  };
}

function conversationThread() {
  return {
    dataset: { conversationRefreshUrl: '/conversation/1/refresh' },
    querySelector: () => null,
    prepend(notice) {
      this.notice = notice;
    },
  };
}

function demoStatusElement() {
  return {
    dataset: { statusUrl: '/project/1/demo-status' },
    querySelector: () => null,
    querySelectorAll: () => [],
  };
}

function load(name, element, statuses) {
  const environment = buildSandbox(element, statuses);
  vm.runInContext(readScript(name), environment.sandbox);
  const behaviors = Object.values(environment.sandbox.Drupal.behaviors);
  behaviors.forEach((behavior) => behavior.attach({}));
  return environment;
}

test('conversation thread marks its refresh as a script request', async () => {
  const environment = load('conversationThread.js', conversationThread(), [200]);
  await environment.tick();
  assert.equal(environment.requests[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
});

for (const status of [401, 403]) {
  test(`conversation thread stops polling on ${status}`, async () => {
    const thread = conversationThread();
    const environment = load('conversationThread.js', thread, [status]);
    await environment.tick();
    assert.equal(environment.intervals.size, 0);
    assert.equal(environment.listeners.has('visibilitychange'), false);
    assert.ok(thread.notice);
    await environment.tick();
    assert.equal(environment.requests.length, 1);
  });
}

for (const status of [500, 502]) {
  test(`conversation thread keeps polling on ${status}`, async () => {
    const environment = load('conversationThread.js', conversationThread(), [status]);
    await environment.tick();
    await environment.tick();
    assert.equal(environment.intervals.size, 1);
    assert.equal(environment.requests.length, 2);
  });
}

test('demo status marks its status request as a script request', async () => {
  const environment = load('demoProjectStatus.js', demoStatusElement(), [200]);
  await environment.tick();
  assert.equal(environment.requests[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.equal(environment.requests[0].options.headers.Accept, 'application/json');
});

for (const status of [401, 403]) {
  test(`demo status stops polling on ${status}`, async () => {
    const environment = load('demoProjectStatus.js', demoStatusElement(), [status]);
    await environment.tick();
    assert.equal(environment.intervals.size, 0);
    await environment.tick();
    assert.equal(environment.requests.length, 1);
  });
}

for (const status of [500, 502]) {
  test(`demo status keeps polling on ${status}`, async () => {
    const environment = load('demoProjectStatus.js', demoStatusElement(), [status]);
    await environment.tick();
    await environment.tick();
    assert.equal(environment.intervals.size, 1);
    assert.equal(environment.requests.length, 2);
  });
}
