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

        // Determine health check URL based on entity type.
        var healthUrl;
        if (entityType === 'soda_scs_stack') {
          healthUrl = '/soda-scs-manager/health/stack/' + entityId;
        }
        else {
          healthUrl = '/soda-scs-manager/health/component/' + entityId;
        }

        // Map API response to health class.
        function updateHealthIcon(healthClass, title) {
          $healthIcon
            .removeClass('soda-scs-manager--health-running soda-scs-manager--health-starting soda-scs-manager--health-stopped soda-scs-manager--health-failure soda-scs-manager--health-unknown')
            .addClass('soda-scs-manager--health-' + healthClass)
            .attr('title', title)
            .attr('aria-label', Drupal.t('Health status: @status', {'@status': title}));
        }

        // Function to check health status.
        function checkHealth() {
          $.ajax({
            url: healthUrl,
            method: 'GET',
            timeout: 8000,
          }).done(function (data) {
            if (data && data.status && data.status.success === true) {
              var status = data.status.status || 'running';
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
