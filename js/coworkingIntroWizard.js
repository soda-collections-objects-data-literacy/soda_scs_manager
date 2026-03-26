/**
 * @file
 * First-login intro slides for the SCS Co-Working Space (dashboard).
 */

(function ($, Drupal, drupalSettings, once) {
  /** Index of the “name + Create” slide (0-based). */
  const SLIDE_WISSKI_FORM = 8;
  /** Index of the post-create success slide. */
  const SLIDE_SUCCESS = 9;

  /**
   * CSRF token URL (respects base path and language prefix).
   */
  function sessionTokenUrl() {
    if (typeof Drupal !== 'undefined' && typeof Drupal.url === 'function' && drupalSettings.path) {
      return Drupal.url('session/token');
    }
    return '/session/token';
  }

  /**
   * Ensures the shared throbber markup exists (see throbberOverlay.js).
   */
  function ensureThrobberOverlay() {
    if (typeof $ === 'undefined' || !$) {
      return;
    }
    if ($('.soda-scs-manager__throbber-overlay').length) {
      return;
    }
    $('body').append(
      '<div class="soda-scs-manager__throbber-overlay">' +
        '<div class="soda-scs-manager__throbber-overlay__content">' +
          '<div class="soda-scs-manager__throbber-overlay__spinner"></div>' +
          '<div class="soda-scs-manager__throbber-overlay__message"></div>' +
          '<div class="soda-scs-manager__throbber-overlay__info"></div>' +
          '<div class="soda-scs-manager__throbber-overlay__steps-container">' +
            '<ul class="soda-scs-manager__steps-list"></ul>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  function showWisskiThrobber() {
    ensureThrobberOverlay();
    if (typeof $ === 'undefined' || !$) {
      return;
    }
    const sm = drupalSettings.sodaScsManager || {};
    const primary =
      sm.throbberPrimaryMessage ||
      Drupal.t('Creating your WissKI environment. Please do not close this window.');
    const info = sm.throbberInfo || '';
    $('.soda-scs-manager__throbber-overlay__message').text(primary);
    $('.soda-scs-manager__throbber-overlay__info').html(info);
    $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');
  }

  function hideWisskiThrobber() {
    if (typeof $ === 'undefined' || !$) {
      return;
    }
    $('.soda-scs-manager__throbber-overlay').removeClass('soda-scs-manager__throbber-overlay--active');
  }

  async function getCsrfToken() {
    const res = await fetch(sessionTokenUrl(), { credentials: 'same-origin' });
    if (res.ok) {
      return res.text();
    }
    return '';
  }

  /**
   * @param {HTMLElement} root
   */
  function hideIntro(root) {
    root.classList.add('hidden');
    root.setAttribute('aria-hidden', 'true');
  }

  /**
   * Reload so the dashboard reflects a new stack and cleared intro state.
   */
  function reloadDashboard() {
    window.location.reload();
  }

  /**
   * @param {HTMLElement} root
   * @return {Promise<boolean>}
   */
  async function persistIntroComplete(root) {
    const cfg = drupalSettings.sodaScsManager?.coworkingIntro;
    if (!cfg?.completeUrl) {
      hideIntro(root);
      return false;
    }
    const token = await getCsrfToken();
    try {
      const res = await fetch(cfg.completeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': token,
        },
      });
      if (!res.ok) {
        return false;
      }
      const data = await res.json();
      return Boolean(data.success);
    } catch (e) {
      return false;
    }
  }

  /**
   * @param {HTMLElement} root
   * @param {number} index
   * @param {number} total
   * @param {HTMLElement|null} nextBtn
   */
  function updateStepUi(root, index, total, nextBtn) {
    const slides = root.querySelectorAll('[data-coworking-intro-slide]');
    slides.forEach((el) => {
      const n = parseInt(el.getAttribute('data-coworking-intro-slide'), 10);
      el.classList.toggle('hidden', n !== index);
    });

    const backBtn = root.querySelector('[data-coworking-intro-back]');
    if (backBtn) {
      backBtn.disabled = index === 0 || index === SLIDE_SUCCESS;
    }
    if (nextBtn) {
      const hideNext = index >= SLIDE_WISSKI_FORM;
      nextBtn.classList.toggle('hidden', hideNext);
      nextBtn.disabled = false;
    }

    const label = root.querySelector('[data-coworking-intro-step-label]');
    if (label) {
      label.textContent = Drupal.t('Step @current of @total', {
        '@current': String(index + 1),
        '@total': String(total),
      });
    }
  }

  /**
   * @param {HTMLElement} root
   */
  function setup(root) {
    const slides = root.querySelectorAll('[data-coworking-intro-slide]');
    const total = slides.length;
    if (!total) {
      return;
    }

    let index = 0;
    const nextBtn = root.querySelector('[data-coworking-intro-next]');
    const skipBtn = root.querySelector('[data-coworking-intro-skip]');
    const nameInput = root.querySelector('[data-coworking-intro-wisski-name]');
    const nameError = root.querySelector('[data-coworking-intro-wisski-error]');
    const openWisskiBtn = root.querySelector('[data-coworking-intro-open-wisski]');
    const finishBtn = root.querySelector('[data-coworking-intro-finish]');
    const successSummary = root.querySelector('[data-coworking-intro-success-summary]');
    const successLink = root.querySelector('[data-coworking-intro-success-details-link]');
    const closeSuccessBtn = root.querySelector('[data-coworking-intro-close-success]');

    function showNameError(msg) {
      if (!nameError) {
        return;
      }
      if (msg) {
        nameError.textContent = msg;
        nameError.hidden = false;
      } else {
        nameError.textContent = '';
        nameError.hidden = true;
      }
    }

    updateStepUi(root, index, total, nextBtn);

    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        if (index < total - 1) {
          index += 1;
          updateStepUi(root, index, total, nextBtn);
        }
      });
    }

    const backBtn = root.querySelector('[data-coworking-intro-back]');
    if (backBtn) {
      backBtn.addEventListener('click', () => {
        if (index > 0 && index !== SLIDE_SUCCESS) {
          index -= 1;
          updateStepUi(root, index, total, nextBtn);
        }
      });
    }

    async function skipOrFinish() {
      await persistIntroComplete(root);
      reloadDashboard();
    }

    if (skipBtn) {
      skipBtn.addEventListener('click', () => {
        skipOrFinish();
      });
    }

    if (finishBtn) {
      finishBtn.addEventListener('click', () => {
        skipOrFinish();
      });
    }

    if (closeSuccessBtn) {
      closeSuccessBtn.addEventListener('click', () => {
        reloadDashboard();
      });
    }

    if (openWisskiBtn && nameInput) {
      openWisskiBtn.addEventListener('click', async () => {
        const raw = (nameInput.value || '').trim();
        if (!raw) {
          showNameError(Drupal.t('Please enter a name for your WissKI environment.'));
          return;
        }
        showNameError('');
        const cfg = drupalSettings.sodaScsManager?.coworkingIntro;
        const quickUrl = cfg?.wisskiQuickCreateUrl;

        if (!quickUrl) {
          showNameError(
            Drupal.t(
              'Automatic WissKI creation is not available. Rebuild the site cache (e.g. drush cr) or contact an administrator.',
            ),
          );
          return;
        }

        openWisskiBtn.disabled = true;
        showWisskiThrobber();
        const token = await getCsrfToken();
        if (!token) {
          hideWisskiThrobber();
          openWisskiBtn.disabled = false;
          showNameError(Drupal.t('Could not load a security token. Reload the page and try again.'));
          return;
        }
        try {
          const res = await fetch(quickUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-Token': token,
            },
            body: JSON.stringify({ label: raw }),
          });
          let data = {};
          try {
            data = await res.json();
          } catch (e) {
            data = {};
          }
          if (!res.ok || !data.success) {
            hideWisskiThrobber();
            openWisskiBtn.disabled = false;
            const errText =
              (data && data.error) ||
              Drupal.t('Could not create WissKI. Check the message below or try again.');
            showNameError(errText);
            return;
          }
          hideWisskiThrobber();
          openWisskiBtn.disabled = false;

          const createdLabel = (data.label && String(data.label)) || raw;
          if (successSummary) {
            successSummary.textContent = Drupal.t(
              'You successfully started @name. It will appear on your dashboard while it is provisioned.',
              { '@name': createdLabel },
            );
          }
          if (successLink && data.redirectUrl) {
            successLink.setAttribute('href', data.redirectUrl);
            successLink.classList.remove('pointer-events-none', 'opacity-50');
          } else if (successLink) {
            successLink.setAttribute('href', '#');
            successLink.classList.add('pointer-events-none', 'opacity-50');
          }

          index = SLIDE_SUCCESS;
          updateStepUi(root, index, total, nextBtn);
        } catch (e) {
          hideWisskiThrobber();
          openWisskiBtn.disabled = false;
          showNameError(Drupal.t('Request failed. Please try again.'));
        }
      });
    }
  }

  Drupal.behaviors.sodaScsCoworkingIntroWizard = {
    attach(context) {
      once('soda-scs-coworking-intro', '#scs-manager--coworking-intro', context).forEach((root) => {
        setup(root);
      });
    },
  };
})(jQuery, Drupal, drupalSettings, once);
