(function ($, Drupal, drupalSettings) {
  const HEALTH_SYMBOLS = {
    running: '●',
    starting: '◔',
    stopped: '⏹',
    problem: '⚠',
    unknown: '○'
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
    const isRunningLink = Boolean(serviceLoginUrl) && variant === Drupal.sodaScsHealth.RUNNING;
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

  function extractCurrentStatus(data) {
    return Drupal.sodaScsHealth.fromPayload(data);
  }

  function showWisskiReadyNotification(serviceLink) {
    var $notification = $('<div class="wisski-ready-notification"></div>');
    var loginUrl = serviceLink.replace(/\/$/, '');

    $notification.html(
      '<div class="notification-content">' +
      '<button class="notification-close" aria-label="' + Drupal.t('Close notification') + '">&times;</button>' +
      '<p class="text--label">' + Drupal.t('Your WissKI is ready!') + '</p>' +
      '<p>' + Drupal.t('If you visit for the first time, you need to login with SODa SCS Client.') + '</br>' +
      Drupal.t('Go to') + ' <a href="' + loginUrl + '" target="_blank">' + loginUrl + '</a></br>' +
      Drupal.t('Click on') + ' <strong>' + Drupal.t('Login with SODa SCS Client') + '</strong></p>' +
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
        const $detailsHealth = $('[data-entity-health]');
        const dotSpan = $("<span class='dot'>.</span>");

        function updateDetailsHealth(variant, text) {
          $detailsHealth
            .removeClass('application__health-status--running application__health-status--starting application__health-status--stopped application__health-status--problem application__health-status--unknown')
            .addClass('application__health-status--' + variant)
            .attr('data-entity-health', variant)
            .text(text);
        }

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
            url: Drupal.url('soda-scs-manager/component/service-url/' + idMatch[1]),
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
            const variant = currentStatus;
            const displayText = Drupal.sodaScsHealth.label(variant);
            const errorTitle = Drupal.sodaScsHealth.detail(data);
            stopLoading();
            updateDetailsHealth(variant, displayText);
            $healthItem.html(renderBadge(
              variant,
              HEALTH_SYMBOLS[variant] || HEALTH_SYMBOLS.unknown,
              displayText,
              variant === Drupal.sodaScsHealth.RUNNING ? undefined : errorTitle,
              variant === Drupal.sodaScsHealth.RUNNING ? serviceLoginUrl : ''
            ));
            if (variant === Drupal.sodaScsHealth.PROBLEM && errorTitle) {
              $healthLabel.addClass('soda-scs-manager--entity-status--api-error').attr('title', errorTitle);
            }
            else {
              $healthLabel.removeClass('soda-scs-manager--entity-status--api-error').removeAttr('title');
            }

            var entityBundle = drupalSettings.entityInfo && drupalSettings.entityInfo.bundle;
            if ((entityBundle === 'soda_scs_wisski_component' || entityBundle === 'soda_scs_wisski_stack') &&
              previousStatus &&
              previousStatus !== Drupal.sodaScsHealth.RUNNING &&
              currentStatus === Drupal.sodaScsHealth.RUNNING) {
              notifyWisskiReadyIfApplicable();
            }
            previousStatus = currentStatus;
          }).fail(function () {
            stopLoading();
            const transportTitle = Drupal.t('Could not refresh status');
            updateDetailsHealth('unknown', Drupal.t('Unknown'));
            $healthItem.html(renderBadge('unknown', HEALTH_SYMBOLS.unknown, Drupal.t('Unknown'), transportTitle, ''));
            $healthLabel.removeClass('soda-scs-manager--entity-status--api-error').removeAttr('title');
          });
        }

        runHealthCheck();
        setInterval(runHealthCheck, 3000);
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
