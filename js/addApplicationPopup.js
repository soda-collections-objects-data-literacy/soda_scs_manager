(function ($, Drupal, once, drupalSettings) {
  /**
   * Loads any <link>/<script> assets referenced by a fetched document that
   * are not already present on the current page, then invokes callback.
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

  Drupal.behaviors.addApplicationPopup = {
    attach: function (context, settings) {
      var activeTrigger = null;

      // The back button lives in the loaded form's action row; park it before the form is cleared.
      var restoreBackButton = function (popupEl) {
        var back = popupEl.querySelector('.add-app-popup__back');
        var step = popupEl.querySelector('.add-app-popup__step--form');
        if (back && step && back.parentElement !== step) {
          step.prepend(back);
        }
      };

      var closeOverlay = function (popupEl) {
        popupEl.hidden = true;
        restoreBackButton(popupEl);

        var selectStep = popupEl.querySelector('.add-app-popup__step--select');
        var formStep = popupEl.querySelector('.add-app-popup__step--form');
        var formContainer = popupEl.querySelector('.add-app-popup__form-container');
        var applicationPreview = popupEl.querySelector('.application-config-preview');
        var errorStepEl = popupEl.querySelector('.add-app-popup__step--error');
        if (errorStepEl) {
          errorStepEl.hidden = true;
        }
        popupEl.querySelectorAll('.add-app-popup-card.is-selected').forEach(function (card) {
          card.classList.remove('is-selected');
        });
        if (selectStep && formStep) {
          formStep.hidden = true;
          selectStep.hidden = false;
        }
        if (formContainer) {
          formContainer.innerHTML = '';
        }
        if (applicationPreview) {
          applicationPreview.hidden = true;
        }

        if (activeTrigger) {
          activeTrigger.setAttribute('aria-expanded', 'false');
          activeTrigger.focus();
          activeTrigger = null;
        }
      };

      once('addApplicationPopupClose', '#add-app-popup', document).forEach(function (popupEl) {
        var selectStep = popupEl.querySelector('.add-app-popup__step--select');
        var formStep = popupEl.querySelector('.add-app-popup__step--form');
        var formContainer = popupEl.querySelector('.add-app-popup__form-container');
        var applicationPreview = popupEl.querySelector('.application-config-preview');
        var backButton = popupEl.querySelector('.add-app-popup__back');
        var errorStep = popupEl.querySelector('.add-app-popup__step--error');
        var errorDetail = popupEl.querySelector('.add-app-popup__error-detail');
        var retryButton = popupEl.querySelector('.add-app-popup__retry');
        var cancelButton = popupEl.querySelector('.add-app-popup__cancel');
        var activeCard = null;

        var updateApplicationPreview = function (card) {
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
        };

        var showFailure = function (detail) {
          if (!errorStep || !formStep) {
            return;
          }
          if (errorDetail) {
            errorDetail.textContent = detail || '';
            errorDetail.hidden = !detail;
          }
          formStep.hidden = true;
          errorStep.hidden = false;
          if (retryButton) {
            retryButton.focus();
          }
        };

        if (retryButton) {
          retryButton.addEventListener('click', function (e) {
            e.preventDefault();
            errorStep.hidden = true;
            formStep.hidden = false;
          });
        }

        if (cancelButton) {
          cancelButton.addEventListener('click', function (e) {
            e.preventDefault();
            closeOverlay(popupEl);
          });
        }

        var showSelectStep = function () {
          restoreBackButton(popupEl);
          activeCard = null;
          popupEl.querySelectorAll('.add-app-popup-card.is-selected').forEach(function (card) {
            card.classList.remove('is-selected');
          });
          if (applicationPreview) {
            applicationPreview.hidden = true;
          }
          if (errorStep) {
            errorStep.hidden = true;
          }
          if (formStep) {
            formStep.hidden = true;
          }
          if (formContainer) {
            formContainer.innerHTML = '';
          }
          if (selectStep) {
            selectStep.hidden = false;
          }
        };

        var loadForm = function (url, card) {
          if (!formContainer || !selectStep || !formStep) {
            window.location.href = url;
            return;
          }

          formContainer.innerHTML = '<p>' + Drupal.t('Loading…') + '</p>';
          popupEl.querySelectorAll('.add-app-popup-card.is-selected').forEach(function (selectedCard) {
            selectedCard.classList.remove('is-selected');
          });
          card.classList.add('is-selected');
          activeCard = card;
          updateApplicationPreview(card);
          selectStep.hidden = true;
          formStep.hidden = false;

          fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('Request failed with status ' + response.status);
              }
              return response.text();
            })
            .then(function (html) {
              var doc = new DOMParser().parseFromString(html, 'text/html');
              var content = doc.querySelector('.dashboard-content') || doc.querySelector('main');

              if (!content) {
                window.location.href = url;
                return;
              }

              var settingsScript = doc.querySelector('script[data-drupal-selector="drupal-settings-json"]');
              if (settingsScript) {
                try {
                  var fetchedSettings = JSON.parse(settingsScript.textContent);
                  $.extend(true, drupalSettings, fetchedSettings);
                }
                catch (parseError) {
                  // Ignore malformed settings; behaviors still attach with existing settings.
                }
              }

              loadMissingAssets(doc, function () {
                renderForm(content);
              });
            })
            .catch(function () {
              window.location.href = url;
            });
        };

        var renderForm = function (content) {
          restoreBackButton(popupEl);
          formContainer.innerHTML = content.innerHTML;

          var infoSummary = formContainer.querySelector('[data-drupal-selector="edit-info"]');
          if (infoSummary) {
            var infoWrapper = infoSummary.closest('.js-form-wrapper, .form-wrapper') || infoSummary;
            infoWrapper.hidden = true;
          }

          // Owner is always the current user when creating from this wizard.
          // The owner widget renders as a fieldset of radio buttons
          // (options_buttons), so hide the whole fieldset, not just
          // the first radio's item wrapper.
          var ownerInput = formContainer.querySelector('[name^="owner"]');
          if (ownerInput) {
            var ownerFieldset = ownerInput.closest('fieldset');
            var ownerWrapper = ownerFieldset
              ? (ownerFieldset.closest('.js-form-item, .form-item') || ownerFieldset)
              : (ownerInput.closest('.js-form-item, .form-item') || ownerInput.parentElement);
            if (ownerWrapper) {
              ownerWrapper.hidden = true;
            }
          }

          Drupal.attachBehaviors(formContainer, drupalSettings);

          var formActions = formContainer.querySelector('.form-actions');
          if (formActions && backButton) {
            formActions.prepend(backButton);
          }

          var firstField = formContainer.querySelector('input:not([type="hidden"]), select, textarea');
          var formEl = formContainer.querySelector('form');
          if (formEl) {
            updateApplicationPreview(activeCard);
          }
          if (firstField) {
            firstField.focus();
          }
        };

        // Submit via fetch so a failed creation stays in the overlay instead of navigating to the form page.
        if (formContainer) {
          formContainer.addEventListener('submit', function (e) {
            var formEl = e.target;
            if (!(formEl instanceof HTMLFormElement)) {
              return;
            }
            e.preventDefault();

            var formData = new FormData(formEl);
            if (e.submitter && e.submitter.name) {
              formData.append(e.submitter.name, e.submitter.value || '');
            }
            var submitButtons = formEl.querySelectorAll('input[type="submit"], button[type="submit"]');
            submitButtons.forEach(function (button) {
              button.disabled = true;
            });

            var hideThrobber = function () {
              if (Drupal.sodaScsThrobberOverlayHide) {
                Drupal.sodaScsThrobberOverlayHide();
              }
            };
            fetch(formEl.getAttribute('action') || window.location.href, {
              method: 'POST',
              credentials: 'same-origin',
              body: formData,
            })
              .then(function (response) {
                if (response.redirected) {
                  window.location.href = response.url;
                  return null;
                }
                return response.text();
              })
              .then(function (html) {
                if (html === null) {
                  return;
                }
                hideThrobber();
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var content = doc.querySelector('.dashboard-content') || doc.querySelector('main');
                var serverError = doc.querySelector('.messages--error');
                if (!content || !content.querySelector('form')) {
                  throw new Error('Unexpected response');
                }
                // Server messages may render outside the content region.
                var serverErrorText = serverError ? serverError.textContent.trim() : '';
                renderForm(content);
                showFailure(serverErrorText);
              })
              .catch(function () {
                hideThrobber();
                submitButtons.forEach(function (button) {
                  button.disabled = false;
                });
                showFailure('');
              });
          });
        }

        if (backButton) {
          backButton.addEventListener('click', function (e) {
            e.preventDefault();
            showSelectStep();
            var firstCard = selectStep ? selectStep.querySelector('.add-app-popup-card') : null;
            if (firstCard) {
              firstCard.focus();
            }
          });
        }

        popupEl.querySelectorAll('.add-app-popup-card').forEach(function (card) {
          card.addEventListener('click', function (e) {
            e.preventDefault();
            var url = card.getAttribute('href');
            if (url) {
              loadForm(url, card);
            }
          });
        });

        var closeButton = popupEl.querySelector('#close-popup');
        if (closeButton) {
          closeButton.addEventListener('click', function (e) {
            e.preventDefault();
            closeOverlay(popupEl);
          });
        }

        popupEl.addEventListener('click', function (e) {
          if (e.target === popupEl || e.target.classList.contains('overlay__backdrop')) {
            closeOverlay(popupEl);
          }
        });

        popupEl.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            e.preventDefault();
            closeOverlay(popupEl);
          }
        });

        popupEl.querySelectorAll('.add-app-popup-card__doc-link').forEach(function (docLink) {
          docLink.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var docUrl = docLink.getAttribute('data-doc-url');
            if (docUrl) {
              window.location.href = docUrl;
            }
          });
        });
      });

      // Only <button> triggers: same class on <a> (e.g. projects page-title link) must navigate.
      once('addApplicationPopup', 'button.soda-scs-manager--add-app-button', context).forEach(function (button) {
        var $button = $(button);

        $button.on('click', function (e) {
          e.preventDefault();
          e.stopPropagation();

          var popup = document.getElementById('add-app-popup');
          if (!popup) {
            return;
          }

          var projectId = $button.attr('data-project-id');
          var createUrls = (drupalSettings.sodaScsManager && drupalSettings.sodaScsManager.addApplicationCreateUrls) || {};
          if (projectId && createUrls.mariadb && createUrls.wisski && createUrls.openGdb) {
            var query = '?project=' + encodeURIComponent(projectId);
            popup.querySelector('a[data-app-type="mariadb"]').setAttribute('href', createUrls.mariadb + query);
            popup.querySelector('a[data-app-type="wisski"]').setAttribute('href', createUrls.wisski + query);
            popup.querySelector('a[data-app-type="openGdb"]').setAttribute('href', createUrls.openGdb + query);
          }
          // Jupyter and WebProtégé tiles are not links when the project
          // already has them.
          var jupyterLink = popup.querySelector('a[data-app-type="jupyter"]');
          if (projectId && createUrls.jupyter && jupyterLink) {
            var jupyterQuery = '?project=' + encodeURIComponent(projectId);
            jupyterLink.setAttribute('href', createUrls.jupyter + jupyterQuery);
          }
          var webprotegeLink = popup.querySelector('a[data-app-type="webprotege"]');
          if (projectId && createUrls.webprotege && webprotegeLink) {
            var webprotegeQuery = '?project=' + encodeURIComponent(projectId);
            webprotegeLink.setAttribute('href', createUrls.webprotege + webprotegeQuery);
          }

          activeTrigger = button;
          popup.hidden = false;
          button.setAttribute('aria-expanded', 'true');
          var firstLink = popup.querySelector('a');
          if (firstLink) {
            firstLink.focus();
          }
        });
      });
    }
  };
})(jQuery, Drupal, once, drupalSettings);

