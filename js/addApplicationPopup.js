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

      var closeOverlay = function (popupEl) {
        popupEl.hidden = true;

        var selectStep = popupEl.querySelector('.add-app-popup__step--select');
        var formStep = popupEl.querySelector('.add-app-popup__step--form');
        var formContainer = popupEl.querySelector('.add-app-popup__form-container');
        if (selectStep && formStep) {
          formStep.hidden = true;
          selectStep.hidden = false;
        }
        if (formContainer) {
          formContainer.innerHTML = '';
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
        var backButton = popupEl.querySelector('.add-app-popup__back');

        var showSelectStep = function () {
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
                formContainer.innerHTML = content.innerHTML;
                Drupal.attachBehaviors(formContainer, drupalSettings);

                var firstField = formContainer.querySelector('input:not([type="hidden"]), select, textarea');
                if (firstField) {
                  firstField.focus();
                }
              });
            })
            .catch(function () {
              window.location.href = url;
            });
        };

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
          if (projectId && createUrls.jupyter) {
            var jupyterQuery = '?project=' + encodeURIComponent(projectId);
            popup.querySelector('a[data-app-type="jupyter"]').setAttribute('href', createUrls.jupyter + jupyterQuery);
          }
          if (projectId && createUrls.webprotege) {
            var webprotegeQuery = '?project=' + encodeURIComponent(projectId);
            popup.querySelector('a[data-app-type="webprotege"]').setAttribute('href', createUrls.webprotege + webprotegeQuery);
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

