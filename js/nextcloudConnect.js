/**
 * @file
 * Nextcloud Connect modal - Login Flow v2.
 *
 * On first visit, if user has not connected Nextcloud, show a modal.
 * User clicks "Connect", we open Nextcloud login in popup, poll until done,
 * store credentials in Keycloak via backend.
 *
 * @see https://docs.nextcloud.com/server/stable/developer_manual/client_apis/LoginFlow/
 */

(function (Drupal, drupalSettings, once) {
  const STATUS_URL = '/soda-scs-manager/nextcloud/connect/status';
  const STATUS_TIMEOUT_MS = 15000;
  const INIT_URL = '/soda-scs-manager/nextcloud/connect/init';
  const POLL_URL = '/soda-scs-manager/nextcloud/connect/poll';
  const POLL_INTERVAL_MS = 2000;
  const POPUP_WIDTH = 600;
  const POPUP_HEIGHT = 700;

  const TOKEN_URL = '/session/token';
  let csrfTokenCache = null;

  async function getCsrfToken() {
    if (csrfTokenCache) return csrfTokenCache;
    const res = await fetch(TOKEN_URL, { credentials: 'same-origin' });
    if (res.ok) {
      csrfTokenCache = await res.text();
      return csrfTokenCache;
    }
    return '';
  }

  async function fetchWithOptions(url, options = {}) {
    const headers = {
      'Accept': 'application/json',
      ...(options.headers || {}),
    };
    if (options.method === 'POST' || options.method === 'PUT' || options.method === 'PATCH') {
      const token = await getCsrfToken();
      if (token) headers['X-CSRF-Token'] = token;
    }
    if (options.body && typeof options.body === 'string' && options.body.startsWith('{')) {
      headers['Content-Type'] = 'application/json';
    }
    return fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: { ...headers, ...(options.headers || {}) },
    });
  }

  async function fetchWithTimeout(url, options = {}, timeoutMs = STATUS_TIMEOUT_MS) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const res = await fetchWithOptions(url, {
        ...options,
        signal: controller.signal,
      });
      clearTimeout(timeoutId);
      return res;
    } catch (e) {
      clearTimeout(timeoutId);
      if (e.name === 'AbortError') {
        const err = new Error(Drupal.t('Request timed out. Nextcloud may be slow or unreachable.'));
        err.aborted = true;
        throw err;
      }
      throw e;
    }
  }

  async function parseJsonResponse(res) {
    const contentType = res.headers.get('Content-Type') || '';
    const isJson = contentType.includes('application/json');
    const text = await res.text();
    if (!isJson) {
      const err = new Error(
        res.ok
          ? Drupal.t('Server returned non-JSON response.')
          : Drupal.t('Server error (HTTP @status). Please ensure you are logged in and try again.', { '@status': res.status })
      );
      err.status = res.status;
      err.text = text;
      throw err;
    }
    if (!text.trim()) {
      throw new Error(Drupal.t('Empty response from server.'));
    }
    try {
      return JSON.parse(text);
    } catch (e) {
      const err = new Error(Drupal.t('Invalid server response. Please try again.'));
      err.original = e;
      err.text = text;
      throw err;
    }
  }

  function showModal(container) {
    const modal = container.querySelector('.scs-manager--nextcloud-connect-modal');
    if (modal) {
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
    }
  }

  function hideModal(container) {
    const modal = container.querySelector('.scs-manager--nextcloud-connect-modal');
    if (modal) {
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
    }
  }

  /**
   * @param {HTMLElement} container
   * @return {NodeListOf<HTMLButtonElement>}
   */
  function getConnectButtons(container) {
    return container.querySelectorAll('.scs-manager--nextcloud-connect-btn');
  }

  function setModalState(container, state, message = '') {
    const connectBtns = getConnectButtons(container);
    const statusEl = container.querySelector('.scs-manager--nextcloud-connect-status');
    const statusInline = container.querySelector('.scs-manager--nextcloud-connect-status-inline');
    const errorEl = container.querySelector('.scs-manager--nextcloud-connect-error');

    connectBtns.forEach((connectBtn) => {
      connectBtn.disabled = state === 'loading' || state === 'success';
    });
    if (statusEl) {
      statusEl.textContent = message;
      statusEl.classList.toggle('hidden', !message);
    }
    if (statusInline && message) {
      statusInline.textContent = message;
    }
    if (errorEl) {
      errorEl.textContent = state === 'error' ? message : '';
      errorEl.classList.toggle('hidden', state !== 'error');
    }
  }

  function initModalDismiss(container) {
    const dismissBtn = container.querySelector('.scs-manager--nextcloud-connect-dismiss');
    if (dismissBtn) {
      dismissBtn.addEventListener('click', () => hideModal(container));
    }
  }

  async function checkStatusAndShowModal(context) {
    const container = document.querySelector('.scs-manager--nextcloud-connect-wrapper');
    if (!container) return;
    if (container.classList.contains('scs-manager--nextcloud-connect-inline')) return;

    try {
      const res = await fetchWithTimeout(STATUS_URL);
      const data = await parseJsonResponse(res);
      if (!data.connected) {
        showModal(container);
      }
    } catch (_) {}
  }

  function getStatusMessage(data) {
    if (!data.connected) return Drupal.t('Not connected');
    if (data.method === 'bearer') {
      return Drupal.t('Connected via SSO');
    }
    if (data.method === 'stored') {
      return Drupal.t('Connected via app password');
    }
    return Drupal.t('Connected');
  }

  function getStatusTitle(data) {
    if (data.method === 'stored' && data.bearer_error) {
      return Drupal.t('Bearer failed: @error', { '@error': data.bearer_error });
    }
    return '';
  }

  function updateStatusCheckResult(fieldset, message, status = '', title = '') {
    const resultEl = fieldset?.querySelector('.scs-manager--nextcloud-status-check-result');
    if (resultEl) {
      resultEl.textContent = message;
      resultEl.dataset.status = status;
      resultEl.title = title;
    }
  }

  async function updateInlineStatus(container, isVerifying = false) {
    const fieldset = container.closest('fieldset');
    const statusEl = container.querySelector('.scs-manager--nextcloud-connect-status-inline');
    const connectBtns = getConnectButtons(container);
    const fallbackHint = container.querySelector('.scs-manager--nextcloud-connect-fallback-hint');
    const verifyBtn = fieldset?.querySelector('.scs-manager--nextcloud-verify-btn');
    if (!statusEl) return;

    // Hide fallback hint during load; keep connect buttons usable (e.g. intro slide).
    if (fallbackHint) fallbackHint.style.display = 'none';
    if (verifyBtn && isVerifying) verifyBtn.disabled = true;
    if (isVerifying) updateStatusCheckResult(fieldset, Drupal.t('Checking...'), 'checking');

    try {
      const res = await fetchWithTimeout(STATUS_URL);
      const data = await parseJsonResponse(res);
      const message = getStatusMessage(data);
      const statusTitle = getStatusTitle(data);
      if (data.connected) {
        statusEl.textContent = message;
        statusEl.dataset.status = 'connected';
        statusEl.dataset.method = data.method || '';
        statusEl.title = statusTitle;
        updateStatusCheckResult(fieldset, message, 'connected', statusTitle);
        connectBtns.forEach((btn) => {
          btn.style.display = 'none';
        });
        if (fallbackHint) fallbackHint.style.display = 'none';
      } else {
        statusEl.textContent = Drupal.t('Not connected');
        statusEl.dataset.status = 'disconnected';
        statusEl.dataset.method = '';
        updateStatusCheckResult(fieldset, Drupal.t('Not connected'), 'disconnected', '');
        connectBtns.forEach((btn) => {
          btn.style.display = '';
        });
        if (fallbackHint) fallbackHint.style.display = '';
      }
    } catch (e) {
      const errMsg = e?.message || Drupal.t('Unable to check status');
      statusEl.textContent = errMsg;
      statusEl.dataset.status = 'error';
      updateStatusCheckResult(fieldset, errMsg, 'error');
      connectBtns.forEach((btn) => {
        btn.style.display = '';
      });
      if (fallbackHint) fallbackHint.style.display = '';
    }
    if (verifyBtn) verifyBtn.disabled = false;
  }

  async function runConnectFlow(container, onSuccess) {
    setModalState(container, 'loading', Drupal.t('Opening Nextcloud login...'));

    try {
      const initRes = await fetchWithOptions(INIT_URL, { method: 'POST', body: JSON.stringify({}) });
      const initData = await parseJsonResponse(initRes);
      if (initData.error || !initData.loginUrl) {
        setModalState(container, 'error', initData.error || Drupal.t('Failed to start Nextcloud login'));
        return;
      }
      const { loginUrl, pollToken, pollEndpoint } = initData;
      const popup = window.open(loginUrl, 'nextcloud_connect', `width=${POPUP_WIDTH},height=${POPUP_HEIGHT},scrollbars=yes,resizable=yes`);
      if (!popup) {
        setModalState(container, 'error', Drupal.t('Please allow popups for this site to connect Nextcloud.'));
        return;
      }
      setModalState(container, 'loading', Drupal.t('Complete the login in the popup window...'));

      const pollInterval = setInterval(async () => {
        if (popup.closed) {
          clearInterval(pollInterval);
          setModalState(container, '', '');
          if (container.classList.contains('scs-manager--nextcloud-connect-inline') && onSuccess) {
            updateInlineStatus(container);
          }
          return;
        }
        try {
          const formData = new FormData();
          formData.append('pollToken', pollToken);
          formData.append('pollEndpoint', pollEndpoint);
          const pollRes = await fetchWithOptions(POLL_URL, {
            method: 'POST',
            body: formData,
            headers: {}, // FormData sets Content-Type with boundary; don't override
          });
          const pollData = await parseJsonResponse(pollRes);
          if (pollData.success) {
            clearInterval(pollInterval);
            popup.close();
            setModalState(container, 'success', Drupal.t('Nextcloud connected successfully!'));
            hideModal(container);
            if (container.classList.contains('scs-manager--nextcloud-connect-inline') && onSuccess) {
              onSuccess();
            }
          } else if (pollData.error && !pollData.pending) {
            clearInterval(pollInterval);
            popup.close();
            setModalState(container, 'error', pollData.error);
          }
        } catch (_) {}
      }, POLL_INTERVAL_MS);
    } catch (e) {
      setModalState(container, 'error', e.message || Drupal.t('Connection failed'));
    }
  }

  Drupal.behaviors.nextcloudConnect = {
    attach(context) {
      once('nextcloud-connect', '.scs-manager--nextcloud-connect-wrapper', context).forEach((container) => {
        const isInline = container.classList.contains('scs-manager--nextcloud-connect-inline');
        const verifyBtn = container.closest('fieldset')?.querySelector('.scs-manager--nextcloud-verify-btn');

        getConnectButtons(container).forEach((connectBtn) => {
          connectBtn.addEventListener('click', () => {
            const onSuccess = isInline ? () => updateInlineStatus(container) : undefined;
            runConnectFlow(container, onSuccess);
          });
        });

        if (verifyBtn) {
          verifyBtn.addEventListener('click', () => updateInlineStatus(container, true));
        }

        if (isInline) {
          updateInlineStatus(container);
        } else {
          initModalDismiss(container);
          checkStatusAndShowModal(context);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
