(function (Drupal, once) {
  'use strict';

  const COUNT_URL = '/soda-scs-manager/messages-unread-count';
  const POLL_INTERVAL_MS = 30000;
  let pollTimer = null;
  let isSessionLost = false;

  // A 401/403 means the session is gone: stop polling, a retry cannot succeed
  // before a reload and each one writes an "access denied" log entry.
  function stopPolling() {
    isSessionLost = true;
    window.clearInterval(pollTimer);
    document.removeEventListener('visibilitychange', refresh);
    window.removeEventListener('focus', refresh);
  }

  function render(count) {
    document.querySelectorAll('.scs-manager--notifications-menu-item').forEach((item) => {
      const badge = item.querySelector('.notification-count');
      if (!badge) {
        return;
      }
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.dataset.notificationCount = String(count);
      badge.hidden = count <= 0;
      item.classList.toggle('has-notifications', count > 0);
    });
  }

  async function refresh() {
    if (document.hidden || isSessionLost) {
      return;
    }
    try {
      const response = await fetch(COUNT_URL, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (response.status === 401 || response.status === 403) {
        stopPolling();
        return;
      }
      if (response.ok) {
        render((await response.json()).count);
      }
    }
    catch (error) {
      // Network hiccup: keep the last known count and retry on next tick.
    }
  }

  Drupal.behaviors.sodaScsNotificationBadge = {
    attach(context) {
      if (!once('scs-notification-badge', 'html', context).length) {
        return;
      }
      pollTimer = window.setInterval(refresh, POLL_INTERVAL_MS);
      document.addEventListener('visibilitychange', refresh);
      window.addEventListener('focus', refresh);
    },
  };
})(Drupal, once);
