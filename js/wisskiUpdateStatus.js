/**
 * @file
 * Polls the progress of a queued or running WissKI update on its page.
 *
 * Shows the latest step and reloads the page once the update has ended, so
 * the version card shows the new version and the outcome.
 */
((Drupal, once) => {
  const POLL_INTERVAL_MS = 5000;

  // The queue worker writes the final step and the outcome right after it
  // marks the operation as ended.
  const RELOAD_DELAY_MS = 3000;

  const ACTIVE_STATUSES = new Set(['queued', 'running']);

  /**
   * Fetches the progress and shows the latest step.
   *
   * @param {HTMLElement} element
   *   Element with data-scs-wisski-update-status-url.
   *
   * @return {Promise<boolean>}
   *   Whether the update is still queued or running.
   */
  async function refresh(element) {
    const response = await fetch(element.dataset.scsWisskiUpdateStatusUrl, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      throw new Error(`WissKI update status request failed with ${response.status}`);
    }
    const data = await response.json();
    const latestStep = Array.isArray(data.steps) && data.steps.length > 0 ? data.steps[0].message : '';
    const stepElement = element.querySelector('[data-scs-wisski-update-step]');
    if (stepElement && latestStep) {
      stepElement.textContent = latestStep;
    }
    return ACTIVE_STATUSES.has(data.operation?.status ?? '');
  }

  Drupal.behaviors.sodaScsWisskiUpdateStatus = {
    attach(context) {
      once('scs-wisski-update-status', '[data-scs-wisski-update-status-url]', context).forEach((element) => {
        const poll = async () => {
          try {
            if (!(await refresh(element))) {
              window.setTimeout(() => window.location.reload(), RELOAD_DELAY_MS);
              return;
            }
          }
          catch (error) {
            // A failed poll is retried; the update itself is not affected.
            console.warn(error);
          }
          window.setTimeout(poll, POLL_INTERVAL_MS);
        };
        window.setTimeout(poll, POLL_INTERVAL_MS);
      });
    },
  };
})(Drupal, once);
