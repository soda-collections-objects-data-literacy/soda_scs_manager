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
    var field = input || container.querySelector('[data-drupal-selector="edit-' + namePrefix + '"]');
    if (!field) {
      return;
    }
    var fieldset = field.closest('fieldset');
    var wrapper = fieldset
      ? (fieldset.closest('.js-form-item, .form-item') || fieldset)
      : (field.closest('.js-form-item, .form-item, .js-form-wrapper, .form-wrapper') || field.parentElement);
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
          review: root.querySelector('[data-cpw-step="review"]'),
          done: root.querySelector('[data-cpw-step="done"]'),
        };
        var appsFormTitle = root.querySelector('[data-cpw-apps-form-title]');
        var applicationPreview = root.querySelector('[data-cpw-step="apps-form"] .application-config-preview');
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

        function updateApplicationPreview(card) {
          if (!applicationPreview || !card) {
            return;
          }
          var productName = card.querySelector('.add-app-popup-card__description');
          var documentationButton = card.querySelector('.add-app-popup-card__doc-link');
          var documentationUrl = documentationButton && documentationButton.getAttribute('data-doc-url');
          var applicationType = card.querySelector('.add-app-popup-card__bundle');
          var documentationLink = applicationPreview.querySelector('.application-config-preview__documentation');
          var title = applicationPreview.querySelector('.application-config-preview__title');
          var type = applicationPreview.querySelector('.application-config-preview__type');
          if (!productName || !applicationType || !documentationUrl || !documentationLink || !title || !type) {
            applicationPreview.hidden = true;
            return;
          }

          title.textContent = productName.textContent.trim();
          type.textContent = applicationType.textContent.trim();
          documentationLink.href = documentationUrl;
          documentationLink.setAttribute('aria-label', documentationButton.getAttribute('aria-label') || '');
          documentationLink.innerHTML = '';
          var documentationIcon = documentationButton.querySelector('svg');
          if (documentationIcon) {
            documentationLink.appendChild(documentationIcon.cloneNode(true));
          }
          applicationPreview.hidden = false;
        }

        var state = {
          phase: 'details',
          projectId: null,
          projectLabel: '',
          projectNote: '',
          doneMessage: '',
          selectedAppTypes: [],
          appQueue: [],
          appQueueIndex: 0,
          appSnapshots: {},
          invitedPeople: [],
          creating: false,
        };

        var SNAPSHOT_SKIP_FIELDS = {
          form_build_id: true,
          form_token: true,
          form_id: true,
          op: true,
        };

        function resetState() {
          state.phase = 'details';
          state.projectId = null;
          state.projectLabel = '';
          state.projectNote = '';
          state.doneMessage = '';
          state.selectedAppTypes = [];
          state.appQueue = [];
          state.appQueueIndex = 0;
          state.appSnapshots = {};
          state.invitedPeople = [];
          state.creating = false;
          if (invitedList) {
            invitedList.innerHTML = '';
          }
          setReviewError('');
          setReviewStatus('');
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
          // Before the selection is confirmed, count the cards that are pressed
          // so the step total includes the forms still ahead.
          var types = (state.phase === 'apps-form' || state.phase === 'members' || state.phase === 'review' || state.phase === 'done')
            ? state.appQueue
            : state.selectedAppTypes;
          types.forEach(function (type) {
            pages.push({ phase: 'apps-form', appType: type, label: Drupal.t('Add applications') });
          });
          pages.push({ phase: 'members', label: Drupal.t('Invite members') });
          pages.push({ phase: 'review', label: Drupal.t('Review') });
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
          skipBtn.hidden = !(state.phase === 'apps-select' || state.phase === 'apps-form' || state.phase === 'members');

          if (state.phase === 'apps-select') {
            nextBtn.textContent = state.selectedAppTypes.length > 0 ? Drupal.t('Next') : Drupal.t('Skip');
          }
          else if (state.phase === 'review') {
            nextBtn.textContent = Drupal.t('Create project');
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
                if (onLoaded) {
                  onLoaded(null);
                }
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
              if (onLoaded) {
                onLoaded(null);
              }
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
            return response.text().then(function (html) {
              // A relative form action never equals the absolute response URL, so
              // that comparison treated validation errors as success. Creation
              // redirects to the project page, which is the only success signal.
              var match = /\/project\/(\d+)/.exec(response.url || '');
              if (match) {
                return { success: true, url: response.url, id: match[1] };
              }
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
        // The project form stays in the page until the review is confirmed.

        function loadDetailsStep() {
          var container = stepEls.details.querySelector('.create-project-wizard__form-container');
          loadForm(settings.addProjectFormUrl, container, function (loaded) {
            if (!loaded) {
              return;
            }
            hideFieldByName(loaded, 'owner');
            hideFieldByName(loaded, 'memberInvitations');
            hideNativeSubmit(loaded);
          });
        }

        function confirmDetailsStep() {
          var container = stepEls.details.querySelector('.create-project-wizard__form-container');
          var formEl = container.querySelector('form');
          if (!formEl) {
            return;
          }
          var labelInput = formEl.querySelector('[name="label[0][value]"]');
          var label = labelInput ? labelInput.value.trim() : '';
          if (!label) {
            if (labelInput) {
              labelInput.setCustomValidity(Drupal.t('Enter a project name.'));
              labelInput.reportValidity();
              labelInput.setCustomValidity('');
              labelInput.focus();
            }
            return;
          }
          if (labelInput && labelInput.getAttribute('aria-invalid') === 'true') {
            labelInput.focus();
            return;
          }
          var noteInput = formEl.querySelector('[name="note[0][value]"]');
          state.projectLabel = label;
          state.projectNote = noteInput ? noteInput.value.trim() : '';
          goToPhase('apps-select');
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
        // Forms are shown with ?deferProject=1 and kept locally. They are
        // submitted only after the project exists.

        function appFormUrl(type, projectId) {
          var url = settings.componentCreateUrls && settings.componentCreateUrls[type];
          if (!url) {
            return '';
          }
          var joiner = url.indexOf('?') === -1 ? '?' : '&';
          if (projectId) {
            return url + joiner + 'project=' + encodeURIComponent(projectId);
          }
          return url + joiner + 'deferProject=1';
        }

        function readSnapshot(formEl) {
          var entries = [];
          Array.prototype.forEach.call(formEl.elements, function (el) {
            if (!el.name || SNAPSHOT_SKIP_FIELDS[el.name]) {
              return;
            }
            if (el.type === 'submit' || el.type === 'button' || el.type === 'image') {
              return;
            }
            if (el.type === 'checkbox' || el.type === 'radio') {
              if (el.checked) {
                entries.push({ name: el.name, value: el.value });
              }
              return;
            }
            if (el.type === 'select-multiple') {
              Array.prototype.forEach.call(el.selectedOptions, function (option) {
                entries.push({ name: el.name, value: option.value });
              });
              return;
            }
            entries.push({ name: el.name, value: el.value });
          });
          return entries;
        }

        function applySnapshot(formEl, entries) {
          var grouped = {};
          entries.forEach(function (entry) {
            if (SNAPSHOT_SKIP_FIELDS[entry.name]) {
              return;
            }
            if (!grouped[entry.name]) {
              grouped[entry.name] = [];
            }
            grouped[entry.name].push(entry.value);
          });
          Object.keys(grouped).forEach(function (name) {
            var nodes = formEl.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]');
            if (!nodes.length) {
              return;
            }
            var values = grouped[name];
            var first = nodes[0];
            if (first.type === 'checkbox' || first.type === 'radio') {
              Array.prototype.forEach.call(nodes, function (node) {
                node.checked = values.indexOf(node.value) !== -1;
              });
              return;
            }
            if (first.type === 'select-multiple') {
              Array.prototype.forEach.call(first.options, function (option) {
                option.selected = values.indexOf(option.value) !== -1;
              });
              return;
            }
            first.value = values[0];
          });
          var label = formEl.querySelector('[name="label[0][value]"]');
          if (label) {
            label.dispatchEvent(new Event('input', { bubbles: true }));
            label.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }

        function rememberAppForm() {
          var formEl = stepEls.appsForm.querySelector('form');
          var type = state.appQueue[state.appQueueIndex];
          if (!formEl || !type) {
            return;
          }
          state.appSnapshots[type] = readSnapshot(formEl);
        }

        function captureAppFormStep() {
          var container = stepEls.appsForm.querySelector('.create-project-wizard__form-container');
          var formEl = container.querySelector('form');
          if (!formEl) {
            return false;
          }
          var labelInput = formEl.querySelector('[name="label[0][value]"]');
          var label = labelInput ? labelInput.value.trim() : '';
          var type = state.appQueue[state.appQueueIndex];
          if (!label) {
            if (labelInput) {
              labelInput.focus();
              labelInput.reportValidity();
            }
            return false;
          }
          // Stack create forms reject labels longer than 25 characters.
          if ((type === 'wisski' || type === 'jupyter') && label.length > 25) {
            labelInput.setCustomValidity(Drupal.t('The label must not exceed 25 characters.'));
            labelInput.reportValidity();
            labelInput.setCustomValidity('');
            return false;
          }
          state.appSnapshots[type] = readSnapshot(formEl);
          return true;
        }

        function loadAppFormStep() {
          var type = state.appQueue[state.appQueueIndex];
          var container = stepEls.appsForm.querySelector('.create-project-wizard__form-container');
          var selectedCard = Array.prototype.find.call(
            stepEls.appsSelect.querySelectorAll('[data-app-type]'),
            function (card) { return card.getAttribute('data-app-type') === type; }
          );
          var url = appFormUrl(type, null);
          updateApplicationPreview(selectedCard);
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
          loadForm(url, container, function (loaded) {
            if (!loaded) {
              return;
            }
            hideFieldByName(loaded, 'owner');
            hideFieldByName(loaded, 'partOfProjects');
            hideFieldByName(loaded, 'info');
            hideNativeSubmit(loaded);
            var formEl = loaded.querySelector('form');
            if (formEl && state.appSnapshots[type]) {
              applySnapshot(formEl, state.appSnapshots[type]);
            }
            if (formEl) {
              updateApplicationPreview(selectedCard);
            }
          });
        }

        // --- Step: members ---------------------------------------------------

        function renderInvitedList() {
          if (!invitedList) {
            return;
          }
          invitedList.innerHTML = '';
          state.invitedPeople.forEach(function (person, index) {
            var li = document.createElement('li');
            li.className = 'mt-3 flex items-center gap-2 text-sm text-stone-800';
            var name = document.createElement('span');
            name.textContent = person;
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn--secondary';
            remove.textContent = Drupal.t('Remove');
            remove.addEventListener('click', function () {
              state.invitedPeople.splice(index, 1);
              renderInvitedList();
            });
            li.appendChild(name);
            li.appendChild(remove);
            invitedList.appendChild(li);
          });
        }

        function addInvitee() {
          var input = root.querySelector('[data-cpw-invite-input]');
          if (!input) {
            return;
          }
          var value = input.value.trim();
          if (!value) {
            return;
          }
          var exists = state.invitedPeople.some(function (person) {
            return person.toLowerCase() === value.toLowerCase();
          });
          if (!exists) {
            state.invitedPeople.push(value);
            renderInvitedList();
          }
          input.value = '';
          input.focus();
        }

        // --- Step: review ----------------------------------------------------

        function setReviewError(text) {
          var el = root.querySelector('[data-cpw-review-error]');
          if (!el) {
            return;
          }
          el.textContent = text || '';
          el.hidden = !text;
        }

        function setReviewStatus(text) {
          var el = root.querySelector('[data-cpw-review-status]');
          if (!el) {
            return;
          }
          el.textContent = text || '';
          el.hidden = !text;
        }

        function appendReviewRow(review, title, value) {
          var block = document.createElement('div');
          block.className = 'create-project-wizard__review-grid';
          var values = Array.isArray(value) ? value : [value];
          values.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'create-project-wizard__review-row';
            var label = document.createElement('strong');
            label.className = 'create-project-wizard__review-label';
            var valueCell = document.createElement('span');
            valueCell.className = 'create-project-wizard__review-value';
            if (item && typeof item === 'object' && item.label) {
              label.textContent = item.label + ':';
              valueCell.textContent = item.value;
            }
            else {
              label.textContent = title + ':';
              valueCell.textContent = item;
            }
            row.appendChild(label);
            row.appendChild(valueCell);
            block.appendChild(row);
          });
          review.appendChild(block);
        }

        function renderReview() {
          var review = root.querySelector('[data-cpw-review]');
          setReviewError('');
          setReviewStatus('');
          if (!review) {
            return;
          }
          review.innerHTML = '';
          appendReviewRow(review, Drupal.t('Project'), state.projectLabel || Drupal.t('None'));
          if (state.projectNote) {
            appendReviewRow(review, Drupal.t('Note'), state.projectNote);
          }
          var apps = state.appQueue.map(function (type) {
            var entries = state.appSnapshots[type] || [];
            var label = '';
            entries.forEach(function (entry) {
              if (entry.name === 'label[0][value]') {
                label = entry.value;
              }
            });
            var typeLabel = appLabels[type] || type;
            return label ? { label: typeLabel, value: label } : typeLabel;
          });
          appendReviewRow(review, Drupal.t('Applications'), apps.length ? apps : [Drupal.t('None')]);
          appendReviewRow(review, Drupal.t('People'), state.invitedPeople.length ? state.invitedPeople.slice() : [Drupal.t('None')]);
        }

        function collectFormErrors(html) {
          if (!html) {
            return [];
          }
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var texts = [];
          doc.querySelectorAll('.form-item--error-message, .messages--error, [data-drupal-messages] [role="alert"] li').forEach(function (el) {
            if (el.querySelector('.error-with-backtrace')) {
              return;
            }
            var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (text && texts.indexOf(text) === -1) {
              texts.push(text);
            }
          });
          return texts;
        }

        function htmlFieldHasError(html, namePrefix) {
          if (!html) {
            return false;
          }
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var input = doc.querySelector('[name^="' + namePrefix + '"]');
          if (!input) {
            return false;
          }
          var item = input.closest('.form-item');
          return !!(item && item.classList.contains('form-item--error'));
        }

        function showFormBanner(container, text) {
          var banner = container.querySelector('[data-cpw-form-banner]');
          if (!banner) {
            banner = document.createElement('p');
            banner.className = 'mt-3 text-sm font-medium text-red-700';
            banner.setAttribute('data-cpw-form-banner', '');
            container.insertBefore(banner, container.firstChild);
          }
          banner.textContent = text;
        }

        function failRemainingApps(type, detail) {
          state.creating = false;
          setBusy(false);
          setReviewStatus('');
          var from = state.appQueue.indexOf(type);
          var remaining = from === -1 ? [] : state.appQueue.slice(from);
          var names = remaining.map(function (item) {
            return appLabels[item] || item;
          }).join(', ');
          var message = Drupal.t('The project was created, but these applications were not: @apps.', {
            '@apps': names || (appLabels[type] || type),
          });
          if (detail) {
            message += ' ' + detail;
          }
          setReviewError(message);
          nextBtn.hidden = true;
          finishBtn.hidden = false;
          prevBtn.hidden = true;
          skipBtn.hidden = true;
        }

        function createQueuedApps(index) {
          if (index >= state.appQueue.length) {
            state.creating = false;
            setBusy(false);
            setReviewStatus('');
            goToPhase('done');
            return;
          }
          var type = state.appQueue[index];
          setReviewStatus(Drupal.t('Creating @app…', {
            '@app': appLabels[type] || type,
          }));
          var staging = root.querySelector('[data-cpw-staging]');
          var url = appFormUrl(type, state.projectId);
          if (!url || !staging) {
            failRemainingApps(type);
            return;
          }
          loadForm(url, staging, function (loaded) {
            if (!loaded) {
              failRemainingApps(type);
              return;
            }
            var formEl = loaded.querySelector('form');
            if (!formEl) {
              failRemainingApps(type);
              return;
            }
            hideFieldByName(loaded, 'owner');
            hideFieldByName(loaded, 'partOfProjects');
            hideNativeSubmit(loaded);
            applySnapshot(formEl, state.appSnapshots[type] || []);
            submitForm(formEl).then(function (result) {
              if (!result.success) {
                failRemainingApps(type, collectFormErrors(result.html).join(' '));
                return;
              }
              createQueuedApps(index + 1);
            }).catch(function () {
              failRemainingApps(type);
            });
          });
        }

        function createProjectFromReview() {
          if (state.creating) {
            return;
          }
          var container = stepEls.details.querySelector('.create-project-wizard__form-container');
          var formEl = container.querySelector('form');
          if (!formEl) {
            setReviewError(Drupal.t('The project form is missing. Go back and enter the project name again.'));
            return;
          }
          var invitations = formEl.querySelector('[name="memberInvitations"]');
          if (invitations) {
            invitations.value = state.invitedPeople.join('\n');
          }
          state.creating = true;
          setBusy(true);
          setReviewError('');
          setReviewStatus(Drupal.t('Creating the project…'));
          submitForm(formEl).then(function (result) {
            if (!result.success) {
              state.creating = false;
              setBusy(false);
              setReviewStatus('');
              renderFormErrors(container, result.html);
              hideFieldByName(container, 'owner');
              hideFieldByName(container, 'memberInvitations');
              hideNativeSubmit(container);
              var message = collectFormErrors(result.html).join(' ') || Drupal.t('The project could not be created. Check the name and the invitations.');
              if (htmlFieldHasError(result.html, 'label') || htmlFieldHasError(result.html, 'note')) {
                goToPhase('details', { reload: false });
                showFormBanner(container, message);
                return;
              }
              setReviewError(message);
              return;
            }
            state.projectId = result.id || null;
            var labelInput = formEl.querySelector('[name="label[0][value]"]');
            if (labelInput && labelInput.value) {
              state.projectLabel = labelInput.value;
            }
            if (!state.projectId) {
              state.creating = false;
              setBusy(false);
              setReviewStatus('');
              setReviewError(Drupal.t('The project was created, but its address could not be read. Open your projects to continue.'));
              return;
            }
            createQueuedApps(0);
          }).catch(function () {
            state.creating = false;
            setBusy(false);
            setReviewStatus('');
            setReviewError(Drupal.t('The project could not be created. Please try again.'));
          });
        }

        // --- Step: done -------------------------------------------------------

        function showDoneStep() {
          if (!successMessage) {
            return;
          }
          if (state.doneMessage) {
            successMessage.textContent = state.doneMessage;
            return;
          }
          successMessage.textContent = Drupal.t('Project @name has been created.', {
            '@name': state.projectLabel || '',
          });
        }

        // --- Phase orchestration ----------------------------------------------

        function goToPhase(phase, options) {
          var reload = !options || options.reload !== false;
          state.phase = phase;
          switch (phase) {
            case 'details':
              showStep('details');
              if (reload) {
                loadDetailsStep();
              }
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
              break;

            case 'review':
              showStep('review');
              renderReview();
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
          if (state.creating) {
            return;
          }
          if (state.phase === 'apps-select') {
            goToPhase('details', { reload: false });
            return;
          }
          if (state.phase === 'apps-form') {
            rememberAppForm();
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
            return;
          }
          if (state.phase === 'review') {
            goToPhase('members');
          }
        }

        function goNext() {
          if (state.creating) {
            return;
          }
          if (state.phase === 'details') {
            confirmDetailsStep();
            return;
          }
          if (state.phase === 'apps-select') {
            state.appQueue = state.selectedAppTypes.slice();
            state.appQueueIndex = 0;
            Object.keys(state.appSnapshots).forEach(function (type) {
              if (state.appQueue.indexOf(type) === -1) {
                delete state.appSnapshots[type];
              }
            });
            if (state.appQueue.length > 0) {
              goToPhase('apps-form');
            }
            else {
              goToPhase('members');
            }
            return;
          }
          if (state.phase === 'apps-form') {
            if (!captureAppFormStep()) {
              return;
            }
            state.appQueueIndex++;
            if (state.appQueueIndex < state.appQueue.length) {
              loadAppFormStep();
              updateFooter();
            }
            else {
              goToPhase('members');
            }
            return;
          }
          if (state.phase === 'members') {
            goToPhase('review');
            return;
          }
          if (state.phase === 'review') {
            createProjectFromReview();
          }
        }

        function goSkip() {
          if (state.creating) {
            return;
          }
          if (state.phase === 'apps-select') {
            state.selectedAppTypes = [];
            state.appQueue = [];
            state.appSnapshots = {};
            goToPhase('members');
            return;
          }
          if (state.phase === 'apps-form') {
            var dropped = state.appQueue.slice(state.appQueueIndex);
            dropped.forEach(function (type) {
              delete state.appSnapshots[type];
            });
            state.appQueue = state.appQueue.slice(0, state.appQueueIndex);
            goToPhase('members');
            return;
          }
          if (state.phase === 'members') {
            goToPhase('review');
          }
        }

        function closeOverlay() {
          if (state.creating) {
            return;
          }
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

        var inviteInput = root.querySelector('[data-cpw-invite-input]');
        var inviteAdd = root.querySelector('[data-cpw-invite-add]');
        if (inviteAdd) {
          inviteAdd.addEventListener('click', function (e) {
            e.preventDefault();
            addInvitee();
          });
        }
        if (inviteInput) {
          inviteInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              addInvitee();
            }
          });
        }

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
