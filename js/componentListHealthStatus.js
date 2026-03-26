(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.componentListHealthStatus = {
    attach: function (context, settings) {
      once('componentListHealthStatus', '.soda-scs-table-list tbody tr', context).forEach(function (row) {
        const $row = $(row);
        const componentId = $row.data('component-id');
        const bundle = $row.data('component-bundle');
        const $healthCell = $row.find('.component-health-status');

        if (!componentId || !$healthCell.length) {
          return;
        }

        // Only update health for components that support health checks.
        if (bundle === 'soda_scs_triplestore_component') {
          return;
        }

        const healthUrl = '/soda-scs-manager/health/component/' + componentId;

        // Initial loading state.
        $healthCell.html('<span class="health-loading">Checking<span class="dots"></span></span>');

        // Add animated dots.
        let dotCount = 0;
        const dotsInterval = setInterval(function () {
          dotCount = (dotCount + 1) % 4;
          $healthCell.find('.dots').text('.'.repeat(dotCount));
        }, 500);

        // Function to update health status.
        function updateHealth() {
          $.ajax({
            url: healthUrl,
            method: 'GET',
            timeout: 5000,
          }).done(function (data) {
            clearInterval(dotsInterval);

            if (data && data.status && data.status.success === true) {
              $healthCell.html('<span class="health-running">● Running</span>');
              $healthCell.removeClass('health-error health-loading').addClass('health-success');
            }
            else {
              const status = (data && data.status && data.status.status) ? data.status.status : '';
              const message = (data && data.status && data.status.message) ? data.status.message : 'Error';
              const error = (data && data.status && data.status.error) ? data.status.error : '';
              const statusLower = (status || '').toLowerCase();
              const messageLower = (message || '').toLowerCase();
              const isProvisioning =
                statusLower === 'starting' ||
                messageLower === 'starting';
              const isUnavailable =
                statusLower === 'unavailable' ||
                messageLower === 'unavailable' ||
                messageLower === 'not available' ||
                messageLower.indexOf('component is not available') !== -1 ||
                messageLower.indexOf('service temporarily unavailable') !== -1;
              if (statusLower === 'unhealthy') {
                $healthCell.html('<span class="health-error" title="' + error + '">● ' + message + '</span>');
                $healthCell.removeClass('health-success health-loading').addClass('health-error');
              }
              else if (isUnavailable || statusLower === 'unknown') {
                $healthCell.html('<span class="health-error" title="' + error + '">● ' + message + '</span>');
                $healthCell.removeClass('health-success health-loading health-pending').addClass('health-error');
              }
              else if (isProvisioning) {
                $healthCell.html('<span class="health-pending" title="' + error + '">● ' + Drupal.t('Starting') + '</span>');
                $healthCell.removeClass('health-success health-loading health-error').addClass('health-pending');
              }
              else if (statusLower === 'stopped' || messageLower === 'stopped' || statusLower === 'paused') {
                $healthCell.html('<span class="health-error" title="' + error + '">● ' + message + '</span>');
                $healthCell.removeClass('health-success health-loading').addClass('health-error');
              }
              else {
                $healthCell.html('<span class="health-error" title="' + error + '">● ' + message + '</span>');
                $healthCell.removeClass('health-success health-loading').addClass('health-error');
              }
            }
          }).fail(function (jqXHR, textStatus, errorThrown) {
            clearInterval(dotsInterval);
            $healthCell.html('<span class="health-pending" title="">● ' + Drupal.t('Could not refresh status') + '</span>');
            $healthCell.removeClass('health-success health-loading health-error').addClass('health-pending');
          });
        }

        // Initial check.
        updateHealth();

        // Update every 10 seconds.
        setInterval(updateHealth, 10000);
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);

