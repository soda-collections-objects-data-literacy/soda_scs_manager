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
      // Create the overlay element only once if it doesn't exist.
      if (!$('.soda-scs-manager__throbber-overlay', context).length) {
        $('body', context).append(`
          <div class="soda-scs-manager__throbber-overlay">
            <div class="soda-scs-manager__throbber-overlay__content">
              <div class="soda-scs-manager__throbber-overlay__spinner"></div>
              <div class="soda-scs-manager__throbber-overlay__message">Performing action, please do not close the window</div>
              <div class="soda-scs-manager__throbber-overlay__info"></div>
            </div>
          </div>
        `);
      }

      // Handle all SODA SCS forms.
      once('throbber-overlay-form', 'form[id^="soda-scs"]', context).forEach(function(form) {
        const $form = $(form);
        const formId = $form.attr('id');

        // Handle form submission event.
        $form.on('submit', function(e) {
          // Check if this is a create form (component or stack).
          const isCreateForm = formId === 'soda-scs-manager-component-create-form' ||
                               formId === 'soda-scs-manager-stack-create-form';

          if (isCreateForm) {
            // Show special message for creation forms.
            const infoMessage = Drupal.t('Please note: After creating the WissKI Environment, it can take up to 5 minutes to setup everything.<br><br>Please check the health status to monitor the startup progress.');
            $('.soda-scs-manager__throbber-overlay__info').html(infoMessage);
          } else {
            // Clear any previous info message.
            $('.soda-scs-manager__throbber-overlay__info').html('');
          }

          // Show the overlay on form submission.
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');
        });
      });

      // Handle action links (e.g. "Check for updates") that cause a full-page load.
      once('throbber-overlay-action-link', 'a.soda-scs-manager__throbber-overlay-trigger', context).forEach(function (link) {
        $(link).on('click', function (e) {
          // Only show overlay for normal left-click navigation (not new-tab / modified clicks).
          const isNormalLeftClick = e.which === 1 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;
          if (!isNormalLeftClick) {
            return;
          }

          const throbberMessage = $(this).attr('data-throbber-message') || Drupal.t('Performing action, please do not close the window');
          $('.soda-scs-manager__throbber-overlay__message').text(throbberMessage);
          $('.soda-scs-manager__throbber-overlay__info').html('');
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');
        });
      });

      // Also handle submit button clicks for additional coverage.
      once('throbber-overlay-submit', '.soda-scs-component--component--form-submit, .soda-scs-stack--stack--form-submit', context).forEach(function(button) {
        $(button).on('click', function(e) {
          const $form = $(this).closest('form');
          const formId = $form.attr('id');

          // Don't show overlay if the form has validation errors.
          if (!$form[0].checkValidity()) {
            return;
          }

          // Check if this is a create form (component or stack).
          const isCreateForm = formId === 'soda-scs-manager-component-create-form' ||
                               formId === 'soda-scs-manager-stack-create-form';

          if (isCreateForm) {
            // Show special message for creation forms.
            const infoMessage = Drupal.t('Please note: After creating the WissKI Environment, it can take up to 5 minutes to setup everything.<br><br>Please check the health status to monitor the startup progress.');
            $('.soda-scs-manager__throbber-overlay__info').html(infoMessage);
          } else {
            // Clear any previous info message.
            $('.soda-scs-manager__throbber-overlay__info').html('');
          }

          // Show the overlay before form submission.
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');
        });
      });
    }
  };

})(jQuery, Drupal, once);
