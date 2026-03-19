(function ($, Drupal, drupalSettings) {
  const HEALTH_SYMBOLS = {
    running: '●',
    starting: '⏳',
    stopped: '⏹',
    error: '⚠'
  };

  function renderBadge(variant, symbol, text, title) {
    const escapedText = $('<div>').text(text).html();
    const escapedTitle = title ? ' title="' + $('<div>').text(title).html() + '"' : '';
    return '<span class="health-badge health-badge--' + variant + '"' + escapedTitle + '>' +
      '<span class="health-badge__symbol" aria-hidden="true">' + symbol + '</span>' +
      '<span class="health-badge__text">' + escapedText + '</span>' +
      '</span>';
  }

  function getStatusVariant(status, message) {
    const s = (status || '').toLowerCase();
    const m = (message || '').toLowerCase();
    if (s === 'running' || s === 'healthy') return 'running';
    if (s === 'starting' || m === 'starting') return 'starting';
    if (s === 'stopped' || m === 'stopped') return 'stopped';
    return 'error';
  }

  Drupal.behaviors.updateHealthStatus = {
    attach: function (context, settings) {
      once('updateHealthStatus', 'html', context).forEach(function () {
        const healthUrl = drupalSettings.entityInfo.healthUrl;
        const $healthItem = $("div.field--name-health div.field__item");
        const $healthLabel = $("div.field--name-health div.field__label");
        const dotSpan = $("<span class='dot'>.</span>");

        let dotsInterval = null;
        let resolved = false;

        function stopLoading() {
          if (resolved) return;
          resolved = true;
          if (dotsInterval) {
            clearInterval(dotsInterval);
            dotsInterval = null;
          }
          $healthItem.find('.dot').remove();
        }

        dotsInterval = setInterval(function () {
          if (resolved) return;
          if ($healthItem.find('.dot').length >= 3) {
            $healthItem.find('.dot').remove();
          }
          $healthItem.append(dotSpan.clone());
        }, 1000);

        function runHealthCheck() {
          $.ajax({
            url: healthUrl,
            method: "GET",
          }).then(function (data) {
            const status = data && data.status ? data.status : data;
            if (status && status.success === true) {
              stopLoading();
              const variant = getStatusVariant(status.status, status.message);
              const displayText = status.status || 'Running';
              $healthItem.html(renderBadge(variant, HEALTH_SYMBOLS[variant], displayText));
              $healthLabel.removeClass('soda-scs-manager--entity-status--api-error').removeAttr('title');
            } else {
              stopLoading();
              const variant = getStatusVariant(status && status.status, status && status.message);
              const displayText = (status && status.message) ? status.message : 'Not available';
              const errorTitle = (status && status.error) ? status.error : '';
              $healthItem.html(renderBadge(variant, HEALTH_SYMBOLS[variant], displayText, errorTitle));
              $healthLabel.addClass('soda-scs-manager--entity-status--api-error').attr('title', errorTitle);
            }
          }).fail(function () {
            stopLoading();
            $healthItem.html(renderBadge('error', HEALTH_SYMBOLS.error, 'Health controller has internal error or is not reachable', 'Health controller has internal error or is not reachable'));
            $healthLabel.addClass('soda-scs-manager--entity-status--api-error').attr('title', 'Health controller has internal error or is not reachable');
          });
        }

        runHealthCheck();
        setInterval(runHealthCheck, 3000);
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
