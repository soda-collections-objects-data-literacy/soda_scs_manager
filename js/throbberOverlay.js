/**
 * @file
 * Throbber overlay functionality for SODA SCS Manager.
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Polling interval ID for progress tracking.
   *
   * @type {number|null}
   */
  let progressPollInterval = null;

  /**
   * Start polling for progress updates.
   *
   * @param {string} operationUuid
   *   The operation UUID to poll for.
   */
  function startProgressPolling(operationUuid) {
    // Clear any existing polling interval.
    if (progressPollInterval !== null) {
      clearInterval(progressPollInterval);
    }

    // Poll every 2 seconds.
    progressPollInterval = setInterval(function() {
      $.ajax({
        url: Drupal.url('soda-scs-manager/progress/' + operationUuid + '/latest-steps'),
        method: 'GET',
        dataType: 'json',
        data: {
          limit: 5,
        },
        success: function(response) {
          // Update steps list if we have steps.
          if (response.steps && response.steps.length > 0) {
            updateStepsList(response.steps);
          }

          // Check if operation is completed or failed.
          if (response.operation && (response.operation.status === 'completed' || response.operation.status === 'failed')) {
            stopProgressPolling();
          }
        },
        error: function(xhr, status, error) {
          // On error, stop polling but keep the overlay visible.
          console.error('Failed to fetch progress:', error);
          stopProgressPolling();
        }
      });
    }, 2000);
  }

  /**
   * Stop polling for progress updates.
   */
  function stopProgressPolling() {
    if (progressPollInterval !== null) {
      clearInterval(progressPollInterval);
      progressPollInterval = null;
    }
  }

  /**
   * Update the steps list (simple replacement, no animations).
   *
   * @param {Array} steps
   *   Array of step objects, ordered from newest to oldest.
   */
  function updateStepsList(steps) {
    const $stepsList = $('.soda-scs-manager__steps-list');
    if (!$stepsList.length) {
      return;
    }

    // Build HTML for all steps (newest first).
    let stepsHtml = '';
    steps.forEach(function(step, index) {
      const stepUuid = step.uuid || 'step-' + index + '-' + (step.message || '').substring(0, 20);
      const message = step.message || '';

      stepsHtml += `
        <li class="soda-scs-manager__step soda-scs-manager__step--running"
            data-step-uuid="${Drupal.checkPlain(stepUuid)}">
          <div class="soda-scs-manager__step__icon">
            <svg class="soda-scs-manager__icon soda-scs-manager__icon--running" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none" opacity="0.3"/>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
          </div>
          <div class="soda-scs-manager__step__label">${Drupal.checkPlain(message)}</div>
        </li>
      `;
    });

    // Replace the entire list.
    $stepsList.html(stepsHtml);
  }

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
              <div class="soda-scs-manager__throbber-overlay__steps-container">
                <ul class="soda-scs-manager__steps-list"></ul>
              </div>
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

          // Check for operation UUID from form data attribute or drupalSettings.
          const operationUuid = $form.data('operation-uuid') ||
                                (settings.sodaScsManager && settings.sodaScsManager.operationUuid);

          // Check if progress polling is supported for this form.
          const progressPollingSupported = $form.data('progress-polling') === true ||
                                           $form.data('progress-polling') === 'true' ||
                                           (settings.sodaScsManager && settings.sodaScsManager.progressPolling === true);

          // Show the overlay on form submission.
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');

          console.log('operationUuid', operationUuid);
          console.log('progressPollingSupported', progressPollingSupported);
          // Start polling if operation UUID is available and polling is supported.
          if (operationUuid && progressPollingSupported) {
            console.log('Starting polling');
            startProgressPolling(operationUuid);
          }
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

          // Check for operation UUID from link data attribute or drupalSettings.
          const operationUuid = $(this).data('operation-uuid') ||
                                (settings.sodaScsManager && settings.sodaScsManager.operationUuid);

          // Check if progress polling is supported for this link.
          const progressPollingSupported = $(this).data('progress-polling') === true ||
                                           $(this).data('progress-polling') === 'true' ||
                                           (settings.sodaScsManager && settings.sodaScsManager.progressPolling === true);
          console.log('progressPollingSupported', progressPollingSupported);
          console.log('operationUuid', operationUuid);
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');

          // Start polling if operation UUID is available and polling is supported.
          if (operationUuid && progressPollingSupported) {
            console.log('Starting polling');
            startProgressPolling(operationUuid);
          }
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

          // Check for operation UUID from form data attribute or drupalSettings.
          const operationUuid = $form.data('operation-uuid') ||
                                (settings.sodaScsManager && settings.sodaScsManager.operationUuid);

          // Check if progress polling is supported for this form.
          const progressPollingSupported = $form.data('progress-polling') === true ||
                                           $form.data('progress-polling') === 'true' ||
                                           (settings.sodaScsManager && settings.sodaScsManager.progressPolling === true);

          // Show the overlay before form submission.
          $('.soda-scs-manager__throbber-overlay').addClass('soda-scs-manager__throbber-overlay--active');

          // Start polling if operation UUID is available and polling is supported.
          if (operationUuid && progressPollingSupported) {
            startProgressPolling(operationUuid);
          }
        });
      });

      // Check for operation UUID in drupalSettings on page load (for redirect scenarios).
      if (settings.sodaScsManager && settings.sodaScsManager.operationUuid) {
        const operationUuid = settings.sodaScsManager.operationUuid;
        const progressPollingSupported = settings.sodaScsManager.progressPolling === true;
        // Only start polling if overlay is already visible and polling is supported.
        if ($('.soda-scs-manager__throbber-overlay').hasClass('soda-scs-manager__throbber-overlay--active') && progressPollingSupported) {
          startProgressPolling(operationUuid);
        }
      }
    }
  };

})(jQuery, Drupal, once);
