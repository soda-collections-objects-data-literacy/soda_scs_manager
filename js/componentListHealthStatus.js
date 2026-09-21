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

        if (bundle === 'soda_scs_triplestore_component') {
          return;
        }

        const healthUrl = Drupal.url('soda-scs-manager/health/component/' + componentId);
        const healthClasses = 'health-running health-stopped health-problem health-unknown health-loading health-success health-error health-pending';

        function applyStatus(status, title) {
          const label = Drupal.sodaScsHealth.label(status);
          $healthCell
            .removeClass(healthClasses)
            .addClass('health-' + status)
            .attr('title', title || '')
            .html('<span class="health-' + status + '">● ' + label + '</span>');
        }

        $healthCell.html('<span class="health-loading">' + Drupal.t('Unknown') + '<span class="dots"></span></span>');
        $healthCell.removeClass(healthClasses).addClass('health-unknown');

        let dotCount = 0;
        const dotsInterval = setInterval(function () {
          dotCount = (dotCount + 1) % 4;
          $healthCell.find('.dots').text('.'.repeat(dotCount));
        }, 500);

        function updateHealth() {
          $.ajax({
            url: healthUrl,
            method: 'GET',
            timeout: 5000,
          }).done(function (data) {
            clearInterval(dotsInterval);
            applyStatus(Drupal.sodaScsHealth.fromPayload(data), Drupal.sodaScsHealth.detail(data));
          }).fail(function () {
            clearInterval(dotsInterval);
            applyStatus('unknown', Drupal.t('Could not refresh status'));
          });
        }

        updateHealth();
        setInterval(updateHealth, 10000);
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
