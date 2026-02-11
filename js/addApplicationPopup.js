(function ($, Drupal, once) {
  Drupal.behaviors.addApplicationPopup = {
    attach: function (context, settings) {
      once('addApplicationPopup', '.soda-scs-manager--add-app-button', context).forEach(function (button) {
        var $button = $(button);
        var $popup = $('#add-app-popup');
        
        // Open popup when add button is clicked.
        $button.on('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          $popup.removeClass('hidden').addClass('flex');
          $popup.attr('aria-hidden', 'false');
          // Focus trap - focus first link in popup.
          $popup.find('a').first().focus();
        });
        
        // Close popup when close button is clicked.
        $('#close-popup').on('click', function(e) {
          e.preventDefault();
          $popup.addClass('hidden').removeClass('flex');
          $popup.attr('aria-hidden', 'true');
          // Return focus to add button.
          $button.focus();
        });
        
        // Close popup when clicking outside the modal content.
        $popup.on('click', function(e) {
          if ($(e.target).is('#add-app-popup')) {
            $popup.addClass('hidden').removeClass('flex');
            $popup.attr('aria-hidden', 'true');
            // Return focus to add button.
            $button.focus();
          }
        });
        
        // Close popup on Escape key.
        $(document).on('keydown', function(e) {
          if (e.key === 'Escape' && $popup.hasClass('flex')) {
            $popup.addClass('hidden').removeClass('flex');
            $popup.attr('aria-hidden', 'true');
            // Return focus to add button.
            $button.focus();
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
