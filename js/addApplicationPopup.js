(function ($, Drupal, once, drupalSettings) {
  Drupal.behaviors.addApplicationPopup = {
    attach: function (context, settings) {
      var activeTrigger = null;

      var closeOverlay = function (popupEl) {
        popupEl.hidden = true;
        if (activeTrigger) {
          activeTrigger.setAttribute('aria-expanded', 'false');
          activeTrigger.focus();
          activeTrigger = null;
        }
      };

      once('addApplicationPopupClose', '#add-app-popup', document).forEach(function (popupEl) {
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
