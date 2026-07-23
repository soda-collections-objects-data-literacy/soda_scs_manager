(function ($, Drupal, drupalSettings) {
  const HEALTH_SYMBOLS = {
    running: '●',
    starting: '⏳',
    stopped: '⏹',
    error: '⚠'
  };

  function escapeAttr(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function renderBadge(variant, symbol, text, title, serviceLoginUrl) {
    const escapedText = $('<div>').text(text).html();
    const escapedTitle = title ? ' title="' + $('<div>').text(title).html() + '"' : '';
    const inner =
      '<span class="health-badge__symbol" aria-hidden="true">' + symbol + '</span>' +
      '<span class="health-badge__text">' + escapedText + '</span>';
    const isRunningLink = Boolean(serviceLoginUrl) && variant === 'running';
    if (isRunningLink) {
      const linkTitle = escapeAttr(Drupal.t('Open the service login page in a new tab.'));
      return (
        '<a class="health-badge health-badge--' + variant + '"' +
        ' href="' + escapeAttr(serviceLoginUrl) + '"' +
        ' target="_blank" rel="noopener noreferrer"' +
        (escapedTitle || (' title="' + linkTitle + '"')) +
        '>' +
        inner +
        '</a>'
      );
    }
    return '<span class="health-badge health-badge--' + variant + '"' + escapedTitle + '>' +
      inner +
      '</span>';
  }

  function getStatusVariant(status, message) {
    const s = (status || '').toLowerCase();
    const m = (message || '').toLowerCase();
    if (s === 'running' || s === 'healthy') return 'running';
    if (s === 'starting' || m === 'starting') return 'starting';
    if (s === 'stopped' || m === 'stopped') return 'stopped';
    if (s === 'paused' || m === 'paused') return 'stopped';
    if (s === 'unhealthy') return 'error';
    if (
      s === 'unavailable' ||
      m === 'unavailable' ||
      m === 'not available' ||
      m.indexOf('component is not available') !== -1 ||
      m.indexOf('service temporarily unavailable') !== -1
    ) {
      return 'error';
    }
    if (s === 'unknown') return 'error';
    return 'error';
  }

  /**
   * Normalize API health payload to a status string (matches dashboardHealthStatus.js).
   */
  function extractCurrentStatus(data) {
    var currentStatus = null;
    if (data && data.status && data.status.success === true) {
      currentStatus = data.status.status || 'running';
      return currentStatus;
    }
    if (data && data.status) {
      var status = data.status.status ? data.status.status : '';
      var message = data.status.message ? data.status.message : 'Error';
      currentStatus = status;
      var statusLower = (status || '').toLowerCase();
      var messageLower = (message || '').toLowerCase();
      if (status === 'starting' || message === 'Starting' || message === 'starting') {
        // keep currentStatus as status
      }
      else if (
        statusLower === 'unavailable' ||
        messageLower === 'unavailable' ||
        messageLower === 'not available' ||
        messageLower.indexOf('component is not available') !== -1 ||
        messageLower.indexOf('service temporarily unavailable') !== -1
      ) {
        currentStatus = 'unavailable';
      }
    }
    return currentStatus;
  }

  function showWisskiReadyNotification(serviceLink) {
    var $notification = $('<div class="wisski-ready-notification"></div>');
    var loginUrl = serviceLink.replace(/\/$/, '');

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

    setTimeout(function () {
      $notification.addClass('show');
    }, 100);

    function dismissNotification() {
      $notification.removeClass('show');
      setTimeout(function () {
        $notification.remove();
      }, 300);
    }

    $notification.find('.notification-close').on('click', dismissNotification);
  }

  Drupal.behaviors.updateHealthStatus = {
    attach: function (context, settings) {
      once('updateHealthStatus', 'html', context).forEach(function () {
        const healthUrl = drupalSettings.entityInfo.healthUrl;
        const serviceLoginUrl = (drupalSettings.entityInfo && drupalSettings.entityInfo.serviceLoginUrl) ? String(drupalSettings.entityInfo.serviceLoginUrl) : '';
        const $healthItem = $("div.field--name-health div.field__item");
        const $healthLabel = $("div.field--name-health div.field__label");
        const dotSpan = $("<span class='dot'>.</span>");

        let dotsInterval = null;
        let resolved = false;
        let previousStatus = null;

        function notifyWisskiReadyIfApplicable() {
          var info = drupalSettings.entityInfo || {};
          var direct = info.serviceLoginUrl ? String(info.serviceLoginUrl) : '';
          if (direct) {
            showWisskiReadyNotification(direct);
            return;
          }
          var idMatch = (healthUrl || '').match(/\/health\/component\/(\d+)/);
          if (!idMatch) {
            return;
          }
          $.ajax({
            url: '/soda-scs-manager/component/service-url/' + idMatch[1],
            method: 'GET',
            timeout: 5000,
            dataType: 'json'
          }).done(function (response) {
            if (response && response.url) {
              showWisskiReadyNotification(response.url);
            }
          });
        }

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
            const currentStatus = extractCurrentStatus(data);
            const status = data && data.status ? data.status : data;
            if (status && status.success === true) {
              stopLoading();
              const variant = getStatusVariant(status.status, status.message);
              const displayText = status.message || status.status || Drupal.t('Running');
              $healthItem.html(renderBadge(variant, HEALTH_SYMBOLS[variant], displayText, undefined, serviceLoginUrl));
              $healthLabel.removeClass('soda-scs-manager--entity-status--api-error').removeAttr('title');
            } else {
              stopLoading();
              const variant = getStatusVariant(status && status.status, status && status.message);
              const displayText = (status && status.message) ? status.message : Drupal.t('Unavailable');
              const errorTitle = (status && status.error) ? status.error : '';
              $healthItem.html(renderBadge(variant, HEALTH_SYMBOLS[variant], displayText, errorTitle, ''));
              if (variant === 'starting') {
                $healthLabel.removeClass('soda-scs-manager--entity-status--api-error').removeAttr('title');
              } else {
                $healthLabel.addClass('soda-scs-manager--entity-status--api-error').attr('title', errorTitle);
              }
            }

            var entityBundle = drupalSettings.entityInfo && drupalSettings.entityInfo.bundle;
            if ((entityBundle === 'soda_scs_wisski_component' || entityBundle === 'soda_scs_wisski_stack') &&
                (previousStatus === 'starting' ) &&
                (currentStatus === 'running' || currentStatus === 'healthy')) {
              notifyWisskiReadyIfApplicable();
            }
            previousStatus = currentStatus;
          }).fail(function () {
            stopLoading();
            const transportTitle = Drupal.t('Could not refresh status');
            $healthItem.html(renderBadge('starting', HEALTH_SYMBOLS.starting, Drupal.t('Checking…'), transportTitle, ''));
            $healthLabel.removeClass('soda-scs-manager--entity-status--api-error').removeAttr('title');
          });
        }

        runHealthCheck();
        setInterval(runHealthCheck, 3000);
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
