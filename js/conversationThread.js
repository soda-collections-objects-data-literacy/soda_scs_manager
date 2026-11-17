/**
 * @file
 * Conversation thread: shows the newest message and refreshes the list.
 *
 * The list is rendered and escaped by the server; it is parsed with DOMParser
 * and swapped in as nodes, never assigned through innerHTML.
 */
(function (Drupal, once) {
  'use strict';

  const POLL_INTERVAL_MS = 30000;
  const NEAR_BOTTOM_PX = 120;

  function newest(container) {
    return container.querySelector('[data-conversation-newest]');
  }

  function scrollToNewest(container) {
    const message = newest(container);
    if (message) {
      message.scrollIntoView({ block: 'end' });
    }
  }

  function isNearBottom() {
    const rest = document.documentElement.scrollHeight - window.scrollY - window.innerHeight;
    return rest < NEAR_BOTTOM_PX;
  }

  function stop(thread) {
    window.clearInterval(Number(thread.dataset.conversationTimer));
    document.removeEventListener('visibilitychange', thread.conversationTick);
    const notice = document.createElement('p');
    notice.className = 'scs-manager--conversation-stopped';
    notice.textContent = Drupal.t('This conversation is no longer available. Reload the page.');
    thread.prepend(notice);
  }

  async function refresh(thread) {
    if (document.hidden || thread.dataset.conversationStopped) {
      return;
    }
    const list = thread.querySelector('[data-conversation-signature]');
    try {
      const response = await fetch(thread.dataset.conversationRefreshUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'text/html' },
      });
      if (response.status === 403 || response.status === 404) {
        // Deleted conversation or expired session: polling would fail forever.
        thread.dataset.conversationStopped = '1';
        stop(thread);
        return;
      }
      if (!response.ok) {
        return;
      }
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      const fresh = doc.querySelector('[data-conversation-signature]');
      if (!fresh || !list || fresh.dataset.conversationSignature === list.dataset.conversationSignature) {
        return;
      }
      const follow = isNearBottom();
      const node = document.importNode(fresh, true);
      list.replaceWith(node);
      Drupal.attachBehaviors(node);
      if (follow) {
        scrollToNewest(thread);
      }
    }
    catch (error) {
      // Network hiccup: keep the list and try again on the next tick.
    }
  }

  Drupal.behaviors.sodaScsConversationThread = {
    attach(context) {
      once('scs-conversation-thread', '[data-conversation-refresh-url]', context).forEach((thread) => {
        if (!window.location.hash || window.location.hash === '#newest') {
          scrollToNewest(thread);
        }
        const tick = () => refresh(thread);
        thread.conversationTick = tick;
        thread.dataset.conversationTimer = String(window.setInterval(tick, POLL_INTERVAL_MS));
        document.addEventListener('visibilitychange', tick);
      });
    },
  };
})(Drupal, once);
