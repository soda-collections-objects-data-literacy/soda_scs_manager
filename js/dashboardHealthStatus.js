(function ($, Drupal, once) {
  Drupal.behaviors.dashboardHealthStatus = {
    attach: function (context, settings) {
      once('dashboardHealthStatus', '.soda-scs-manager--card-content-wrapper[data-entity-id]', context).forEach(function (wrapper) {
        var $wrapper = $(wrapper);
        var entityId = $wrapper.data('entity-id');
        var entityType = $wrapper.data('entity-type');
        var $healthIcon = $wrapper.find('.soda-scs-manager--health-icon');

        if (!entityId || !entityType || !$healthIcon.length) {
          return;
        }

        // Store previous status for transition detection.
        var previousStatus = null;

        // Determine health check URL based on entity type.
        var healthUrl;
        var serviceUrlJsonEndpoint;
        if (entityType === 'soda_scs_stack') {
          healthUrl = '/soda-scs-manager/health/stack/' + entityId;
          serviceUrlJsonEndpoint = '/soda-scs-manager/stack/service-url/' + entityId;
        }
        else {
          healthUrl = '/soda-scs-manager/health/component/' + entityId;
          serviceUrlJsonEndpoint = '/soda-scs-manager/component/service-url/' + entityId;
        }

        // Function to show notification popup.
        function showWisskiReadyNotification(serviceLink) {
          var $notification = $('<div class="wisski-ready-notification"></div>');
          // Ensure proper URL formatting (remove trailing slash if present).
          var baseUrl = serviceLink.replace(/\/$/, '');
          var loginUrl = baseUrl + '/user/login';

          $notification.html(
            '<div class="notification-content">' +
              '<button class="notification-close" aria-label="' + Drupal.t('Close notification') + '">&times;</button>' +
              '<div class="notification-icon">&#10003;</div>' +
              '<h3>' + Drupal.t('Your WissKI is ready!') + '</h3>' +
              '<p>' + Drupal.t('If you visit for the first time, you need to login with SODa SCS Client.') + '</p>' +
              '<p>' + Drupal.t('Go to') + ' <a href="' + loginUrl + '" target="_blank">' + loginUrl + '</a></p>' +
              '<p>' + Drupal.t('Click on') + ' <strong>' + Drupal.t('Login with SODa SCS Client') + '</strong></p>' +
            '</div>'
          );

          $('body').append($notification);

          // Fade in the notification.
          setTimeout(function() {
            $notification.addClass('show');
          }, 100);

          // Dismiss notification function.
          function dismissNotification() {
            $notification.removeClass('show');
            setTimeout(function() {
              $notification.remove();
            }, 300);
          }

          // Close button handler.
          $notification.find('.notification-close').on('click', dismissNotification);
        }

        // Map API response to health class and toggle overlay for not-running.
        function updateHealthIcon(healthClass, title) {
          $healthIcon
            .removeClass('soda-scs-manager--health-running soda-scs-manager--health-starting soda-scs-manager--health-stopped soda-scs-manager--health-failure soda-scs-manager--health-unknown')
            .addClass('soda-scs-manager--health-' + healthClass)
            .attr('title', title)
            .attr('aria-label', Drupal.t('Health status: @status', {'@status': title}));

          if (healthClass === 'running') {
            $wrapper.removeClass('soda-scs-manager--card--not-running');
          }
          else {
            $wrapper.addClass('soda-scs-manager--card--not-running');
          }
        }

        // Function to check health status.
        function checkHealth() {
          $.ajax({
            url: healthUrl,
            method: 'GET',
            timeout: 8000,
            dataType: 'json',
          }).done(function (data) {
            var currentStatus = null;

            if (data && data.status && data.status.success === true) {
              var status = data.status.status || 'running';
              currentStatus = status;

              if (status === 'running' || status === 'healthy') {
                updateHealthIcon('running', Drupal.t('Running'));
              }
              else if (status === 'starting') {
                updateHealthIcon('starting', Drupal.t('Starting'));
              }
              else if (status === 'stopped') {
                updateHealthIcon('stopped', Drupal.t('Stopped'));
              }
              else {
                updateHealthIcon('running', Drupal.t('Running'));
              }
            }
            else {
              var status = (data && data.status && data.status.status) ? data.status.status : '';
              var message = (data && data.status && data.status.message) ? data.status.message : 'Error';
              currentStatus = status;

              if (status === 'starting' || message === 'Starting' || message === 'starting') {
                updateHealthIcon('starting', Drupal.t('Starting'));
              }
              else if (status === 'stopped' || message === 'Stopped' || message === 'stopped') {
                updateHealthIcon('stopped', Drupal.t('Stopped'));
              }
              else {
                updateHealthIcon('failure', message);
              }
            }

            // Check for transition from starting to running for WissKI stacks.
            if (entityType === 'soda_scs_stack' &&
                previousStatus === 'starting' &&
                (currentStatus === 'running' || currentStatus === 'healthy')) {

              // Check if this is a WissKI stack by looking at the card type.
              var cardType = $wrapper.closest('.soda-scs-manager--type--card').find('.soda-scs-manager--card-type').text().trim();

              if (cardType.toLowerCase().includes('wisski')) {
                // Get the service URL from the JSON endpoint.
                $.ajax({
                  url: serviceUrlJsonEndpoint,
                  method: 'GET',
                  timeout: 5000,
                  dataType: 'json'
                }).done(function (response) {
                  if (response && response.url) {
                    showWisskiReadyNotification(response.url);
                  }
                }).fail(function (xhr, status, error) {
                  console.error('Failed to get service URL:', status, error);
                });
              }
            }

            // Update previous status for next check.
            previousStatus = currentStatus;
          }).fail(function () {
            updateHealthIcon('failure', Drupal.t('Unavailable'));
          });
        }

        // Initial check.
        checkHealth();

        // Poll every 15 seconds.
        setInterval(checkHealth, 15000);
      });
    }
  };
})(jQuery, Drupal, once);
