(function ($, Drupal, once, drupalSettings) {
  Drupal.behaviors.addApplicationPopup = {
    attach: function (context, settings) {
      var $popup = $('#add-app-popup', context).addBack('#add-app-popup');
      if (!$popup.length) {
        $popup = $('#add-app-popup');
      }

      once('addApplicationPopupClose', '#add-app-popup', document).forEach(function (popupEl) {
        var $modal = $(popupEl);

        $modal.find('#close-popup').on('click', function (e) {
          e.preventDefault();
          $modal.addClass('hidden').removeClass('flex');
          $modal.attr('aria-hidden', 'true');
        });

        $modal.on('click', function (e) {
          if ($(e.target).is('#add-app-popup')) {
            $modal.addClass('hidden').removeClass('flex');
            $modal.attr('aria-hidden', 'true');
          }
        });

        $(document).on('keydown.addApplicationPopup', function (e) {
          if (e.key === 'Escape' && $modal.hasClass('flex')) {
            $modal.addClass('hidden').removeClass('flex');
            $modal.attr('aria-hidden', 'true');
          }
        });
      });

      // Only <button> triggers: same class on <a> (e.g. projects page-title link) must navigate.
      once('addApplicationPopup', 'button.soda-scs-manager--add-app-button', context).forEach(function (button) {
        var $button = $(button);

        $button.on('click', function (e) {
          e.preventDefault();
          e.stopPropagation();

          var projectId = $button.attr('data-project-id');
          var createUrls = (drupalSettings.sodaScsManager && drupalSettings.sodaScsManager.addApplicationCreateUrls) || {};
          if (projectId && createUrls.mariadb && createUrls.wisski && createUrls.openGdb) {
            var query = '?project=' + encodeURIComponent(projectId);
            $popup.find('a[data-app-type="mariadb"]').attr('href', createUrls.mariadb + query);
            $popup.find('a[data-app-type="wisski"]').attr('href', createUrls.wisski + query);
            $popup.find('a[data-app-type="openGdb"]').attr('href', createUrls.openGdb + query);
          }

          $popup.removeClass('hidden').addClass('flex');
          $popup.attr('aria-hidden', 'false');
          $popup.find('a').first().focus();
        });
      });
    }
  };
})(jQuery, Drupal, once, drupalSettings);
