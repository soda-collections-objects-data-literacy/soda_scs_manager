/**
 * @file
 * Throbber overlay functionality for SODA SCS Manager.
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Adds a throbber overlay to the page when forms are submitted.
   */
  Drupal.behaviors.sodaScsThrobberOverlay = {
    attach: function (context, settings) {
      // Create the overlay element only once if it doesn't exist
      if (!$('.soda-scs-manager__throbber-overlay', context).length) {
        $('body', context).append(`
          <div class="soda-scs-manager__throbber-overlay">
            <div class="soda-scs-manager__throbber-overlay__spinner"></div>
            <div class="soda-scs-manager__throbber-overlay__message">Performing action, please do not close the window</div>
          </div>
        `);
      }

      // Add click handler to form submit buttons
      once('throbber-overlay-submit', '.soda-scs-component--component--form-submit', context).forEach(function(button) {
        $(button).on('click', function(e) {
          // Don't show overlay if the form has validation errors
          if (!$(this).closest('form')[0].checkValidity()) {
            return;
          }
          // Show the overlay before form submission
          console.log('show throbber overlay above');
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');
        });
      });

      // Handle all form submissions within the SODA SCS Manager
      once('throbber-overlay-form', 'form[id^="soda-scs"]', context).forEach(function(form) {
        $(form).on('submit', function(e) {
          console.log('show throbber overlay beneath');
          // Show the overlay before form submission
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');
        });
      });
    }
  };

})(jQuery, Drupal, once);
