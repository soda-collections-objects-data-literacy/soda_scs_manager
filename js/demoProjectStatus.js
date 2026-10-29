/**
 * @file
 * Polls the status of demo projects that are being set up or reset.
 *
 * Updates the shimmer steps, replaces the application cards as soon as an
 * application exists, and reloads the page once the job has finished.
 */
((Drupal, once) => {
  const POLL_INTERVAL_MS = 3000;

  /**
   * @param {Array<{key: string, present: boolean}>|undefined} apps
   * @return {number}
   */
  function presentCount(apps) {
    if (!Array.isArray(apps)) {
      return 0;
    }
    return apps.filter((app) => app.present).length;
  }

  /**
   * @return {number}
   */
  function applicationCardCount() {
    const container = document.querySelector('.project__applications');
    if (!container) {
      return 0;
    }
    return container.querySelectorAll('.application--card').length;
  }

  /**
   * Marks each step done or still waiting. The first missing step keeps the
   * pulse so the row shows where setup is.
   *
   * @param {HTMLElement} element
   * @param {Array<{key: string, label: string, present: boolean}>|undefined} apps
   */
  function updateApps(element, apps) {
    if (!Array.isArray(apps)) {
      return;
    }
    let markedCurrent = false;
    apps.forEach((app) => {
      const item = element.querySelector(`[data-scs-demo-app="${CSS.escape(app.key)}"]`);
      if (!item) {
        return;
      }
      const present = Boolean(app.present);
      const isCurrent = !present && !markedCurrent;
      if (isCurrent) {
        markedCurrent = true;
      }
      item.dataset.present = present ? 'true' : 'false';
      item.classList.toggle('text-emerald-900', present);
      item.classList.toggle('text-gray-400', !present);
      const dot = item.querySelector('[data-scs-demo-app-dot]');
      if (dot) {
        dot.classList.toggle('bg-emerald-500', present);
        dot.classList.toggle('bg-gray-200', !present);
        dot.classList.toggle('animate-pulse', isCurrent);
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
    const message = element.querySelector('[data-scs-demo-status-message]');
    if (message && data.message) {
      message.textContent = data.message;
      message.hidden = false;
    }
    updateApps(element, data.apps);
    if (data.isBusy && presentCount(data.apps) > applicationCardCount()) {
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
