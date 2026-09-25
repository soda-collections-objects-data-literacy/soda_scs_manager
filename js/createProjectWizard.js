(function ($, Drupal, once, drupalSettings) {
  /**
   * Loads <link>/<script> assets referenced by a fetched document that are
   * not already present on the current page, then invokes callback.
   */
  function loadMissingAssets(doc, callback) {
    var toLoad = [];

    doc.querySelectorAll('head link[rel="stylesheet"]').forEach(function (link) {
      var href = link.getAttribute('href');
      if (href && !document.querySelector('link[href="' + href + '"]')) {
        toLoad.push({ type: 'css', url: href });
      }
    });

    doc.querySelectorAll('script[src]').forEach(function (script) {
      var src = script.getAttribute('src');
      if (src && !document.querySelector('script[src="' + src + '"]')) {
        toLoad.push({ type: 'js', url: src });
      }
    });

    if (toLoad.length === 0) {
      callback();
      return;
    }

    var pending = toLoad.length;
    var done = function () {
      pending--;
      if (pending <= 0) {
        callback();
      }
    };

    toLoad.forEach(function (asset) {
      if (asset.type === 'css') {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = asset.url;
        link.onload = done;
        link.onerror = done;
        document.head.appendChild(link);
      }
      else {
        var script = document.createElement('script');
        script.src = asset.url;
        script.onload = done;
        script.onerror = done;
        document.body.appendChild(script);
      }
    });
  }

  /**
   * Extracts the main content region from a fetched HTML document.
   */
  function extractContent(doc) {
    return doc.querySelector('.dashboard-content') || doc.querySelector('main');
  }

  /**
   * Merges drupalSettings found in a fetched document into the current page.
   */
  function mergeFetchedSettings(doc) {
    var settingsScript = doc.querySelector('script[data-drupal-selector="drupal-settings-json"]');
    if (!settingsScript) {
      return;
    }
    try {
      var fetchedSettings = JSON.parse(settingsScript.textContent);
      $.extend(true, drupalSettings, fetchedSettings);
    }
    catch (parseError) {
      // Ignore malformed settings; behaviors still attach with existing settings.
    }
  }

  function hideFieldByName(container, namePrefix) {
    var input = container.querySelector('[name^="' + namePrefix + '"]');
    if (!input) {
      return;
    }
    var fieldset = input.closest('fieldset');
    var wrapper = fieldset
      ? (fieldset.closest('.js-form-item, .form-item') || fieldset)
      : (input.closest('.js-form-item, .form-item') || input.parentElement);
    if (wrapper) {
      wrapper.hidden = true;
    }
  }

  function hideNativeSubmit(container) {
    container.querySelectorAll('input[type="submit"], button[type="submit"], .form-actions').forEach(function (el) {
      el.hidden = true;
    });
  }

  Drupal.behaviors.createProjectWizard = {
    attach: function (context) {
      once('createProjectWizardTrigger', '.soda-scs-manager--create-project-trigger', context).forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var popup = document.getElementById('create-project-wizard');
          if (!popup) {
            return;
          }
          popup.dispatchEvent(new CustomEvent('cpw:open', { detail: { trigger: trigger } }));
        });
      });

      once('createProjectWizard', '#create-project-wizard', document).forEach(function (root) {
        var settings = (drupalSettings.sodaScsManager && drupalSettings.sodaScsManager.createProjectWizard) || {};
        var body = root.querySelector('.create-project-wizard__body');
        var stepEls = {
          details: root.querySelector('[data-cpw-step="details"]'),
          appsSelect: root.querySelector('[data-cpw-step="apps-select"]'),
          appsForm: root.querySelector('[data-cpw-step="apps-form"]'),
          members: root.querySelector('[data-cpw-step="members"]'),
          done: root.querySelector('[data-cpw-step="done"]'),
        };
        var appsFormTitle = root.querySelector('[data-cpw-apps-form-title]');
        var invitedList = root.querySelector('[data-cpw-invited-list]');
        var successMessage = root.querySelector('[data-cpw-success-message]');
        var stepLabelEl = root.querySelector('[data-cpw-step-label]');
        var prevBtn = root.querySelector('[data-cpw-prev]');
        var nextBtn = root.querySelector('[data-cpw-next]');
        var skipBtn = root.querySelector('[data-cpw-skip]');
        var finishBtn = root.querySelector('[data-cpw-finish]');
        var closeBtn = root.querySelector('#create-project-wizard-close');
        var activeTrigger = null;

        var appLabels = {
          jupyter: Drupal.t('JupyterHub'),
          webprotege: Drupal.t('WebProtégé'),
          wisski: Drupal.t('WissKI Environment'),
          mariadb: Drupal.t('Database'),
          openGdb: Drupal.t('Triplestore'),
        };

        var state = {
          phase: 'details',
          projectId: null,
          projectLabel: '',
          selectedAppTypes: [],
          appQueue: [],
          appQueueIndex: 0,
          invitedCount: 0,
        };

        function resetState() {
          state.phase = 'details';
          state.projectId = null;
          state.projectLabel = '';
          state.selectedAppTypes = [];
          state.appQueue = [];
          state.appQueueIndex = 0;
          state.invitedCount = 0;
          if (invitedList) {
            invitedList.innerHTML = '';
          }
          root.querySelectorAll('.create-project-wizard__app-card[aria-pressed="true"]').forEach(function (card) {
            card.setAttribute('aria-pressed', 'false');
          });
        }

        function showStep(name) {
          Object.keys(stepEls).forEach(function (key) {
            var el = stepEls[key];
            if (el) {
              el.hidden = key !== name;
            }
          });
        }

        function computePages() {
          var pages = [
            { phase: 'details', label: Drupal.t('Create new project') },
            { phase: 'apps-select', label: Drupal.t('Add applications') },
          ];
          state.appQueue.forEach(function (type) {
            pages.push({ phase: 'apps-form', appType: type, label: Drupal.t('Add applications') });
          });
          pages.push({ phase: 'members', label: Drupal.t('Invite members') });
          return pages;
        }

        function currentPageIndex(pages) {
          for (var i = 0; i < pages.length; i++) {
            if (pages[i].phase === state.phase) {
              if (state.phase === 'apps-form' && pages[i].appType !== state.appQueue[state.appQueueIndex]) {
                continue;
              }
              return i;
            }
          }
          return 0;
        }

        function updateFooter() {
          if (state.phase === 'done') {
            stepLabelEl.textContent = Drupal.t('Done');
            prevBtn.hidden = true;
            nextBtn.hidden = true;
            skipBtn.hidden = true;
            finishBtn.hidden = false;
            return;
          }

          var pages = computePages();
          var idx = currentPageIndex(pages);
          var page = pages[idx];

          stepLabelEl.textContent = page.label + ' — ' + Drupal.t('@current/@total', {
            '@current': String(idx + 1),
            '@total': String(pages.length),
          });

          prevBtn.hidden = false;
          prevBtn.disabled = state.phase === 'details';
          nextBtn.hidden = false;
          finishBtn.hidden = true;
          skipBtn.hidden = !(state.phase === 'apps-select' || state.phase === 'apps-form');

          if (state.phase === 'apps-select') {
            nextBtn.textContent = state.selectedAppTypes.length > 0 ? Drupal.t('Next') : Drupal.t('Skip');
          }
          else if (state.phase === 'members') {
            nextBtn.textContent = Drupal.t('Finish');
          }
          else {
            nextBtn.textContent = Drupal.t('Next');
          }
        }

        function setBusy(busy) {
          nextBtn.disabled = busy;
          prevBtn.disabled = busy || state.phase === 'details';
          skipBtn.disabled = busy;
        }

        function showLoadError(container) {
          container.innerHTML = '<p class="create-project-wizard__error">' + Drupal.t('Could not load the form. Please try again.') + '</p>';
        }

        /**
         * Fetches a Drupal form page and embeds its content into a container.
         */
        function loadForm(url, container, onLoaded) {
          container.innerHTML = '<p>' + Drupal.t('Loading…') + '</p>';
          fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('Request failed with status ' + response.status);
              }
              return response.text();
            })
            .then(function (html) {
              var doc = new DOMParser().parseFromString(html, 'text/html');
              var content = extractContent(doc);
              if (!content) {
                showLoadError(container);
                return;
              }
              mergeFetchedSettings(doc);
              loadMissingAssets(doc, function () {
                container.innerHTML = content.innerHTML;
                Drupal.attachBehaviors(container, drupalSettings);
                if (onLoaded) {
                  onLoaded(container);
                }
                var firstField = container.querySelector('input:not([type="hidden"]):not([type="submit"]), select, textarea');
                if (firstField) {
                  firstField.focus();
                }
              });
            })
            .catch(function () {
              showLoadError(container);
            });
        }

        /**
         * Submits a loaded Drupal form via fetch, following redirects.
         *
         * Resolves with { success: true, url } on redirect (save succeeded),
         * or { success: false, html } when the form was re-rendered with
         * validation errors.
         */
        function submitForm(formEl) {
          var submitControl = formEl.querySelector('input[type="submit"], button[type="submit"]');
          var formData = new FormData(formEl);
          if (submitControl && submitControl.name) {
            formData.append(submitControl.name, submitControl.value || '');
          }
          return fetch(formEl.getAttribute('action') || window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
          }).then(function (response) {
            if (response.redirected || (response.url && response.url !== formEl.getAttribute('action'))) {
              return response.text().then(function () {
                return { success: true, url: response.url };
              });
            }
            return response.text().then(function (html) {
              return { success: false, html: html };
            });
          });
        }

        function renderFormErrors(container, html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var content = extractContent(doc);
          if (!content) {
            showLoadError(container);
            return;
          }
          mergeFetchedSettings(doc);
          loadMissingAssets(doc, function () {
            container.innerHTML = content.innerHTML;
            Drupal.attachBehaviors(container, drupalSettings);
          });
        }

        // --- Step: details ------------------------------------------------

        function loadDetailsStep() {
          var container = stepEls.details.querySelector('.create-project-wizard__form-container');
          loadForm(settings.addProjectFormUrl, container, function (loaded) {
            hideFieldByName(loaded, 'owner');
            hideFieldByName(loaded, 'memberInvitations');
            hideNativeSubmit(loaded);
          });
        }

        function submitDetailsStep() {
          var container = stepEls.details.querySelector('.create-project-wizard__form-container');
          var formEl = container.querySelector('form');
          if (!formEl) {
            return;
          }
          setBusy(true);
          submitForm(formEl).then(function (result) {
            setBusy(false);
            if (result.success) {
              var match = /\/project\/(\d+)/.exec(result.url || '');
              state.projectId = match ? match[1] : null;
              var labelInput = formEl.querySelector('[name="label[0][value]"]');
              state.projectLabel = labelInput ? labelInput.value : '';
              goToPhase('apps-select');
            }
            else {
              renderFormErrors(container, result.html);
              hideFieldByName(container, 'owner');
              hideFieldByName(container, 'memberInvitations');
              hideNativeSubmit(container);
            }
          }).catch(function () {
            setBusy(false);
          });
        }

        // --- Step: apps-select ---------------------------------------------

        function toggleAppCard(card) {
          var type = card.getAttribute('data-app-type');
          var pressed = card.getAttribute('aria-pressed') === 'true';
          var next = !pressed;
          card.setAttribute('aria-pressed', String(next));
          var idx = state.selectedAppTypes.indexOf(type);
          if (next && idx === -1) {
            state.selectedAppTypes.push(type);
          }
          else if (!next && idx !== -1) {
            state.selectedAppTypes.splice(idx, 1);
          }
          updateFooter();
        }

        root.querySelectorAll('.create-project-wizard__app-card').forEach(function (card) {
          card.addEventListener('click', function (e) {
            if (e.target.closest('.add-app-popup-card__doc-link')) {
              return;
            }
            toggleAppCard(card);
          });
          card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
              e.preventDefault();
              toggleAppCard(card);
            }
          });
        });

        root.querySelectorAll('.add-app-popup-card__doc-link').forEach(function (docLink) {
          docLink.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var docUrl = docLink.getAttribute('data-doc-url');
            if (docUrl) {
              window.open(docUrl, '_blank', 'noopener');
            }
          });
        });

        // --- Step: apps-form -------------------------------------------------

        function loadAppFormStep() {
          var type = state.appQueue[state.appQueueIndex];
          var container = stepEls.appsForm.querySelector('.create-project-wizard__form-container');
          var url = settings.componentCreateUrls && settings.componentCreateUrls[type];
          if (appsFormTitle) {
            appsFormTitle.textContent = Drupal.t('Configure @app (@current of @total)', {
              '@app': appLabels[type] || type,
              '@current': String(state.appQueueIndex + 1),
              '@total': String(state.appQueue.length),
            });
          }
          if (!url) {
            showLoadError(container);
            return;
          }
          loadForm(url + '?project=' + encodeURIComponent(state.projectId), container, function (loaded) {
            hideFieldByName(loaded, 'owner');
            hideFieldByName(loaded, 'partOfProjects');
            hideNativeSubmit(loaded);
          });
        }

        function submitAppFormStep() {
          var container = stepEls.appsForm.querySelector('.create-project-wizard__form-container');
          var formEl = container.querySelector('form');
          if (!formEl) {
            return;
          }
          setBusy(true);
          submitForm(formEl).then(function (result) {
            setBusy(false);
            if (result.success) {
              state.appQueueIndex++;
              if (state.appQueueIndex < state.appQueue.length) {
                loadAppFormStep();
                updateFooter();
              }
              else {
                goToPhase('members');
              }
            }
            else {
              renderFormErrors(container, result.html);
              hideFieldByName(container, 'owner');
              hideFieldByName(container, 'partOfProjects');
              hideNativeSubmit(container);
            }
          }).catch(function () {
            setBusy(false);
          });
        }

        // --- Step: members ---------------------------------------------------

        function loadMembersStep() {
          var container = stepEls.members.querySelector('.create-project-wizard__form-container');
          var url = (settings.addMemberFormUrlTemplate || '').replace('__PROJECT_ID__', state.projectId);
          loadForm(url, container, function (loaded) {
            var formEl = loaded.querySelector('form');
            if (formEl) {
              formEl.addEventListener('submit', onMemberFormSubmit);
            }
          });
        }

        function onMemberFormSubmit(e) {
          e.preventDefault();
          var formEl = e.currentTarget;
          var input = formEl.querySelector('[name="memberInvitations"]');
          var invitee = input ? input.value.trim() : '';
          if (!invitee) {
            return;
          }
          setBusy(true);
          submitForm(formEl).then(function (result) {
            setBusy(false);
            if (result.success) {
              state.invitedCount++;
              if (invitedList) {
                var li = document.createElement('li');
                li.className = 'create-project-wizard__invited-item';
                li.textContent = invitee;
                invitedList.appendChild(li);
              }
              formEl.reset();
              input.focus();
            }
            else {
              var container = stepEls.members.querySelector('.create-project-wizard__form-container');
              renderFormErrors(container, result.html);
              var newForm = container.querySelector('form');
              if (newForm) {
                newForm.addEventListener('submit', onMemberFormSubmit);
              }
            }
          }).catch(function () {
            setBusy(false);
          });
        }

        // --- Step: done -------------------------------------------------------

        function showDoneStep() {
          if (successMessage) {
            successMessage.textContent = Drupal.t('Project @name has been created.', {
              '@name': state.projectLabel || '',
            });
          }
        }

        // --- Phase orchestration ----------------------------------------------

        function goToPhase(phase) {
          state.phase = phase;
          switch (phase) {
            case 'details':
              showStep('details');
              loadDetailsStep();
              break;

            case 'apps-select':
              showStep('appsSelect');
              break;

            case 'apps-form':
              showStep('appsForm');
              loadAppFormStep();
              break;

            case 'members':
              showStep('members');
              loadMembersStep();
              break;

            case 'done':
              showStep('done');
              showDoneStep();
              break;

            default:
              break;
          }
          updateFooter();
        }

        function goPrev() {
          if (state.phase === 'apps-select') {
            // Project already exists; nothing before it to go back to.
            return;
          }
          if (state.phase === 'apps-form') {
            if (state.appQueueIndex > 0) {
              state.appQueueIndex--;
              loadAppFormStep();
              updateFooter();
            }
            else {
              goToPhase('apps-select');
            }
            return;
          }
          if (state.phase === 'members') {
            if (state.appQueue.length > 0) {
              state.appQueueIndex = state.appQueue.length - 1;
              goToPhase('apps-form');
            }
            else {
              goToPhase('apps-select');
            }
          }
        }

        function goNext() {
          if (state.phase === 'details') {
            submitDetailsStep();
            return;
          }
          if (state.phase === 'apps-select') {
            state.appQueue = state.selectedAppTypes.slice();
            state.appQueueIndex = 0;
            if (state.appQueue.length > 0) {
              goToPhase('apps-form');
            }
            else {
              goToPhase('members');
            }
            return;
          }
          if (state.phase === 'apps-form') {
            submitAppFormStep();
            return;
          }
          if (state.phase === 'members') {
            goToPhase('done');
          }
        }

        function goSkip() {
          if (state.phase === 'apps-select') {
            state.selectedAppTypes = [];
            state.appQueue = [];
            goToPhase('members');
            return;
          }
          if (state.phase === 'apps-form') {
            goToPhase('members');
          }
        }

        function closeOverlay() {
          root.hidden = true;
          if (activeTrigger) {
            activeTrigger.setAttribute('aria-expanded', 'false');
            activeTrigger.focus();
            activeTrigger = null;
          }
          resetState();
        }

        root.addEventListener('cpw:open', function (e) {
          activeTrigger = e.detail.trigger;
          resetState();
          root.hidden = false;
          activeTrigger.setAttribute('aria-expanded', 'true');
          goToPhase('details');
        });

        prevBtn.addEventListener('click', function (e) {
          e.preventDefault();
          goPrev();
        });
        nextBtn.addEventListener('click', function (e) {
          e.preventDefault();
          goNext();
        });
        skipBtn.addEventListener('click', function (e) {
          e.preventDefault();
          goSkip();
        });
        finishBtn.addEventListener('click', function (e) {
          e.preventDefault();
          if (state.projectId && settings.projectCanonicalUrlTemplate) {
            window.location.href = settings.projectCanonicalUrlTemplate.replace('__PROJECT_ID__', state.projectId);
          }
          else {
            closeOverlay();
          }
        });
        closeBtn.addEventListener('click', function (e) {
          e.preventDefault();
          closeOverlay();
        });
        root.addEventListener('click', function (e) {
          if (e.target === root || e.target.classList.contains('overlay__backdrop')) {
            closeOverlay();
          }
        });
        root.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            e.preventDefault();
            closeOverlay();
          }
        });
      });
    },
  };
})(jQuery, Drupal, once, drupalSettings);
