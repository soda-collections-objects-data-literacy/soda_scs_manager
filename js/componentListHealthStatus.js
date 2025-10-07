(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.componentListHealthStatus = {
    attach: function (context, settings) {
      once('componentListHealthStatus', '.soda-scs-component-list tbody tr', context).forEach(function (row) {
        const $row = $(row);
        const componentId = $row.data('component-id');
        const bundle = $row.data('component-bundle');
        const $healthCell = $row.find('.component-health-status');

        if (!componentId || !$healthCell.length) {
          return;
        }

        // Only update health for components that support health checks.
        if (
          bundle === 'soda_scs_filesystem_component' ||
          bundle === 'soda_scs_triplestore_component'
        ) {
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
              const message = (data && data.status && data.status.message) ? data.status.message : 'Error';
              const error = (data && data.status && data.status.error) ? data.status.error : '';
              $healthCell.html('<span class="health-error" title="' + error + '">● ' + message + '</span>');
              $healthCell.removeClass('health-success health-loading').addClass('health-error');
            }
          }).fail(function (jqXHR, textStatus, errorThrown) {
            clearInterval(dotsInterval);
            $healthCell.html('<span class="health-error" title="Health controller error">● Unavailable</span>');
            $healthCell.removeClass('health-success health-loading').addClass('health-error');
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

