/**
 * @file
 * Refreshes demo version rows while a build is running.
 *
 * Reloads the page once a build leaves the building state, so the row
 * actions match the new status.
 */
((Drupal, once) => {
  const POLL_INTERVAL_MS = 5000;

  Drupal.behaviors.sodaScsManagerDemoVersionStatus = {
    attach(context) {
      once('scs-demo-versions', '[data-demo-versions]', context).forEach((table) => {
        if (!table.querySelector('[data-demo-version-state="building"]')) {
          return;
        }
        const timer = window.setInterval(async () => {
          try {
            const response = await fetch(table.dataset.demoVersions, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
              return;
            }
            const data = await response.json();
            let finished = false;
            for (const version of data.versions || []) {
              const cell = table.querySelector(`[data-demo-version-status="${version.id}"]`);
              if (!cell) {
                continue;
              }
              if (cell.dataset.demoVersionState === 'building' && version.status !== 'building') {
                finished = true;
              }
              cell.dataset.demoVersionState = version.status;
              cell.textContent = version.text;
            }
            if (finished || !table.querySelector('[data-demo-version-state="building"]')) {
              window.clearInterval(timer);
              window.location.reload();
            }
          }
          catch (error) {
            console.warn(error);
          }
        }, POLL_INTERVAL_MS);
      });
    },
  };
})(Drupal, once);
