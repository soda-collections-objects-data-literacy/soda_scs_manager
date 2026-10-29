/**
 * @file
 * Polls the status of demo projects that are being set up or reset.
 *
 * Drops a line once that application is ready, replaces the application
 * cards as soon as an application exists, and reloads the page once the job
 * has finished.
 */
((Drupal, once) => {
  const POLL_INTERVAL_MS = 3000;

  /**
   * @return {number}
   */
  function applicationCardCount() {
    const container = document.querySelector('.project__applications');
    if (!container) {
      return 0;
    }
    return container.querySelectorAll('.application--card:not(.application--card--add)').length;
  }

  /**
   * Rebuilds the pending lines. A line that is no longer reported is removed.
   *
   * @param {HTMLElement} element
   * @param {Array<{key: string, label: string, detail: string}>|undefined} apps
   */
  function updateApps(element, apps) {
    const list = element.querySelector('[data-scs-demo-apps]');
    if (!list || !Array.isArray(apps)) {
      return;
    }
    const pending = new Set(apps.map((app) => app.key));
    list.querySelectorAll('[data-scs-demo-app]').forEach((item) => {
      if (!pending.has(item.getAttribute('data-scs-demo-app'))) {
        item.remove();
      }
    });
    apps.forEach((app) => {
      let item = list.querySelector(`[data-scs-demo-app="${CSS.escape(app.key)}"]`);
      if (!item) {
        item = document.createElement('li');
        item.className = 'flex items-baseline justify-between gap-3';
        item.setAttribute('data-scs-demo-app', app.key);
        const label = document.createElement('span');
        label.textContent = app.label;
        const detail = document.createElement('span');
        detail.className = 'text-gray-500';
        detail.setAttribute('data-scs-demo-app-detail', '');
        item.append(label, detail);
        list.appendChild(item);
      }
      const detail = item.querySelector('[data-scs-demo-app-detail]');
      if (detail) {
        detail.textContent = app.detail || '';
      }
    });
  }

  /**
   * Replaces the application card container with the one from a fresh page
   * render, so a card appears as soon as its application exists.
   *
   * @return {Promise<void>}
   */
  async function refreshApplications() {
    const current = document.querySelector('.project__applications');
    if (!current) {
      return;
    }
    const response = await fetch(window.location.href, {
      credentials: 'same-origin',
      headers: { Accept: 'text/html' },
    });
    if (!response.ok) {
      throw new Error(`Application refresh failed with ${response.status}`);
    }
    const html = await response.text();
    const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('.project__applications');
    if (!fresh || !document.contains(current)) {
      return;
    }
    if (typeof Drupal.detachBehaviors === 'function') {
      Drupal.detachBehaviors(current, undefined, 'unload');
    }
    current.replaceWith(fresh);
    Drupal.attachBehaviors(fresh);
  }

  /**
   * Fetches the status and updates the label, message and steps.
   *
   * @param {HTMLElement} element
   *   Element with data-status-url.
   *
   * @return {Promise<boolean>}
   *   Whether the demo is still busy.
   */
  async function refresh(element) {
    const response = await fetch(element.dataset.statusUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      throw new Error(`Demo status request failed with ${response.status}`);
    }
    const data = await response.json();
    const label = element.querySelector('[data-scs-demo-status-label]');
    if (label && data.statusLabel) {
      label.textContent = data.statusLabel;
    }
    updateApps(element, data.apps);
    if (data.isBusy && Number(data.createdCount) > applicationCardCount()) {
      await refreshApplications();
    }
    return Boolean(data.isBusy);
  }

  Drupal.behaviors.sodaScsManagerDemoProjectStatus = {
    attach(context) {
      once('scs-demo-status', '[data-scs-demo-status][data-is-busy="true"]', context).forEach((element) => {
        let refreshInFlight = false;
        const timer = window.setInterval(async () => {
          if (refreshInFlight) {
            return;
          }
          refreshInFlight = true;
          try {
            if (!(await refresh(element))) {
              window.clearInterval(timer);
              window.location.reload();
            }
          }
          catch (error) {
            // Transient errors (deploys, network) must not stop the polling.
            console.warn(error);
          }
          finally {
            refreshInFlight = false;
          }
        }, POLL_INTERVAL_MS);
      });
    },
  };
})(Drupal, once);
