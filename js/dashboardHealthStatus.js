(function ($, Drupal, once, drupalSettings) {
  Drupal.behaviors.dashboardHealthStatus = {
    attach: function (context, settings) {
      once('dashboardHealthStatus', '.soda-scs-manager--card-content-wrapper[data-entity-id]', context).forEach(function (wrapper) {
        var $wrapper = $(wrapper);
        var entityId = $wrapper.data('entity-id');
        var entityType = $wrapper.data('entity-type');
        var $healthIcon = $wrapper.find('.soda-scs-manager--health-icon');
        var $healthControl = $wrapper.find('.soda-scs-manager--health-control');
        var $banner = $wrapper.find('.soda-scs-manager--card-status-banner');

        if (!entityId || !entityType || !$healthIcon.length) {
          return;
        }

        function dashboardAdminMail() {
          var sm = (drupalSettings && drupalSettings.sodaScsManager) ? drupalSettings.sodaScsManager : {};
          return (sm.dashboardAdminMail && String(sm.dashboardAdminMail).trim()) ? String(sm.dashboardAdminMail).trim() : '';
        }

        // Store previous status for transition detection.
        var previousStatus = null;

        // Determine health check URL based on entity type.
        var healthUrl;
        var serviceUrlJsonEndpoint;
        if (entityType === 'soda_scs_stack') {
          healthUrl = Drupal.url('soda-scs-manager/health/stack/' + entityId);
          serviceUrlJsonEndpoint = Drupal.url('soda-scs-manager/stack/service-url/' + entityId);
        }
        else {
          healthUrl = Drupal.url('soda-scs-manager/health/component/' + entityId);
          serviceUrlJsonEndpoint = Drupal.url('soda-scs-manager/component/service-url/' + entityId);
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

        function setOverlayTone(tone) {
          $wrapper
            .removeClass('soda-scs-manager--card--status-starting soda-scs-manager--card--status-stopped soda-scs-manager--card--status-offline');
          if (tone) {
            $wrapper.addClass(tone);
          }
        }

        function clearBanner() {
          $banner.empty();
        }

        function setBannerStarting() {
          clearBanner();
          var $throbber = $('<div class="soda-scs-manager--card-status-banner__throbber" role="presentation" aria-hidden="true"></div>');
          var $p1 = $('<p class="soda-scs-manager--card-status-banner__primary"></p>').text(Drupal.t('Startup in progress'));
          var $p2 = $('<p class="soda-scs-manager--card-status-banner__secondary"></p>').text(
            Drupal.t('Containers and services are being provisioned. This may take several minutes; status updates automatically.')
          );
          $banner.append($throbber, $p1, $p2);
        }

        function setBannerChecking() {
          clearBanner();
          var $p1 = $('<p class="soda-scs-manager--card-status-banner__primary"></p>').text(Drupal.t('Checking status'));
          var $p2 = $('<p class="soda-scs-manager--card-status-banner__secondary"></p>').text(
            Drupal.t('We have not confirmed whether this application is running yet. The dashboard checks automatically in the background.')
          );
          $banner.append($p1, $p2);
        }

        function setBannerStopped() {
          clearBanner();
          var $p1 = $('<p class="soda-scs-manager--card-status-banner__primary"></p>').text(Drupal.t('Stopped'));
          var $p2 = $('<p class="soda-scs-manager--card-status-banner__secondary"></p>').text(Drupal.t('This application is not running.'));
          $banner.append($p1, $p2);
        }

        function setBannerOffline(detailMessage) {
          clearBanner();
          var $p1 = $('<p class="soda-scs-manager--card-status-banner__primary"></p>').text(Drupal.t('Application offline'));
          var mail = dashboardAdminMail();
          var $p2 = $('<p class="soda-scs-manager--card-status-banner__secondary soda-scs-manager--card-status-banner__contact"></p>');
          if (detailMessage) {
            $p2.append($('<span class="soda-scs-manager--card-status-banner__detail"></span>').text(detailMessage));
            $p2.append($('<br>'));
          }
          if (mail) {
            $p2.append(document.createTextNode(Drupal.t('If you need help, contact') + ' '));
            $p2.append($('<a>', { href: 'mailto:' + mail, class: 'soda-scs-manager--card-status-banner__mailto', text: mail }));
            $p2.append(document.createTextNode('.'));
          }
          else {
            $p2.append(document.createTextNode(Drupal.t('If you need help, contact your site administrator.')));
          }
          $banner.append($p1, $p2);
        }

        function healthStateFromClass(healthClass) {
          if (healthClass === 'running') {
            return 'running';
          }
          if (healthClass === 'stopped') {
            return 'stopped';
          }
          if (healthClass === 'starting') {
            return 'starting';
          }
          return 'other';
        }

        function closeHealthActions() {
          if (!$healthControl.length) {
            return;
          }
          $healthControl.removeClass('is-open');
          $healthIcon.attr('aria-expanded', 'false');
        }

        function openHealthActions() {
          if (!$healthControl.length) {
            return;
          }
          var state = $healthControl.attr('data-health-state');
          if (state !== 'running' && state !== 'stopped') {
            return;
          }
          $healthControl.addClass('is-open');
          $healthIcon.attr('aria-expanded', 'true');
        }

        function isCoarsePointer() {
          return window.matchMedia && window.matchMedia('(hover: none)').matches;
        }

        // Map API response to health class, overlay, and banner.
        function updateHealthIcon(healthClass, title, bannerKind, bannerDetail) {
          $healthIcon
            .removeClass('soda-scs-manager--health-running soda-scs-manager--health-starting soda-scs-manager--health-stopped soda-scs-manager--health-failure soda-scs-manager--health-unknown')
            .addClass('soda-scs-manager--health-' + healthClass)
            .attr('title', title)
            .attr('aria-label', Drupal.t('Health status: @status', {'@status': title}));

          if ($healthControl.length) {
            $healthControl.attr('data-health-state', healthStateFromClass(healthClass));
            if (healthClass !== 'running' && healthClass !== 'stopped') {
              closeHealthActions();
            }
          }

          if (healthClass === 'running') {
            $wrapper.removeClass('soda-scs-manager--card--not-running');
            setOverlayTone('');
            clearBanner();
          }
          else {
            $wrapper.addClass('soda-scs-manager--card--not-running');
            if (bannerKind === 'starting') {
              setOverlayTone('soda-scs-manager--card--status-starting');
              setBannerStarting();
            }
            else if (bannerKind === 'checking') {
              setOverlayTone('');
              setBannerChecking();
            }
            else if (bannerKind === 'stopped') {
              setOverlayTone('soda-scs-manager--card--status-stopped');
              setBannerStopped();
            }
            else if (bannerKind === 'offline') {
              setOverlayTone('soda-scs-manager--card--status-offline');
              setBannerOffline(bannerDetail || '');
            }
            else {
              setOverlayTone('soda-scs-manager--card--status-offline');
              setBannerOffline(bannerDetail || '');
            }
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
              var statusOkLower = (status || '').toLowerCase();

              if (statusOkLower === 'running' || statusOkLower === 'healthy') {
                updateHealthIcon('running', Drupal.t('Running'));
              }
              else if (statusOkLower === 'starting') {
                updateHealthIcon('starting', Drupal.t('Starting'), 'starting');
              }
              else if (statusOkLower === 'stopped') {
                updateHealthIcon('stopped', Drupal.t('Stopped'), 'stopped');
              }
              else if (statusOkLower === 'unknown') {
                updateHealthIcon('unknown', Drupal.t('Unknown'), 'checking');
              }
              else {
                updateHealthIcon('running', Drupal.t('Running'));
              }
            }
            else {
              var status = (data && data.status && data.status.status) ? data.status.status : '';
              var message = (data && data.status && data.status.message) ? data.status.message : 'Error';
              currentStatus = status;
              var statusLower = (status || '').toLowerCase();
              var messageLower = (message || '').toLowerCase();

              if (statusLower === 'starting') {
                updateHealthIcon('starting', Drupal.t('Starting'), 'starting');
              }
              else if (status === 'stopped' || message === 'Stopped' || message === 'stopped') {
                updateHealthIcon('stopped', Drupal.t('Stopped'), 'stopped');
              }
              else if (statusLower === 'paused') {
                updateHealthIcon('stopped', message, 'stopped');
              }
              else if (statusLower === 'unhealthy') {
                updateHealthIcon('failure', message, 'offline', message);
              }
              else if (
                statusLower === 'unavailable' ||
                messageLower === 'unavailable' ||
                messageLower === 'not available' ||
                messageLower.indexOf('component is not available') !== -1 ||
                messageLower.indexOf('service temporarily unavailable') !== -1
              ) {
                updateHealthIcon('failure', message, 'offline', message);
                currentStatus = 'unavailable';
              }
              else if (statusLower === 'unknown') {
                updateHealthIcon('unknown', Drupal.t('Unknown'), 'checking');
              }
              else {
                updateHealthIcon('failure', message, 'offline', message);
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
            previousStatus = 'unknown';
            updateHealthIcon('unknown', Drupal.t('Could not refresh status'), 'offline', Drupal.t('Could not reach the status service.'));
          });
        }

        function confirmLifecycleAction(action) {
          if (action === 'stop') {
            return window.confirm(Drupal.t('Stop this application? It will be unavailable until you start it again. Unsaved work may be lost.'));
          }
          if (action === 'restart') {
            return window.confirm(Drupal.t('Restart this application? It will be briefly unavailable. Unsaved work may be lost.'));
          }
          return true;
        }

        function announceLifecycle(message) {
          if (Drupal.announce) {
            Drupal.announce(message);
          }
        }

        function runLifecycleAction(actionButton) {
          var action = actionButton.getAttribute('data-lifecycle-action');
          var url = actionButton.getAttribute('data-lifecycle-url');
          if (!action || !url || $healthControl.hasClass('is-busy')) {
            return;
          }
          if (!confirmLifecycleAction(action)) {
            return;
          }

          $healthControl.addClass('is-busy');
          $healthControl.find('.soda-scs-manager--health-action').prop('disabled', true);
          if (action === 'start' || action === 'restart') {
            updateHealthIcon('starting', Drupal.t('Starting'), 'starting');
          }
          else {
            updateHealthIcon('stopped', Drupal.t('Stopped'), 'stopped');
          }

          var csrfRequest = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
            .then(function (tokenResponse) {
              return tokenResponse.ok ? tokenResponse.text() : '';
            });

          csrfRequest.then(function (csrfToken) {
            return $.ajax({
              url: url,
              method: 'POST',
              timeout: 60000,
              dataType: 'json',
              headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {},
            });
          }).then(function (data) {
            $healthControl.removeClass('is-busy');
            $healthControl.find('.soda-scs-manager--health-action').prop('disabled', false);
            closeHealthActions();
            if (data && data.success) {
              announceLifecycle(data.message || Drupal.t('The application status was updated.'));
            }
            else {
              var failMessage = (data && (data.message || data.error))
                ? (data.message || data.error)
                : Drupal.t('The application could not be updated.');
              announceLifecycle(failMessage);
            }
            checkHealth();
          }).catch(function () {
            $healthControl.removeClass('is-busy');
            $healthControl.find('.soda-scs-manager--health-action').prop('disabled', false);
            announceLifecycle(Drupal.t('The application could not be updated.'));
            checkHealth();
          });
        }

        if ($healthControl.length) {
          $healthIcon.on('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var state = $healthControl.attr('data-health-state');
            if (state !== 'running' && state !== 'stopped') {
              return;
            }
            if (isCoarsePointer() || $healthControl.hasClass('is-open')) {
              if ($healthControl.hasClass('is-open')) {
                closeHealthActions();
              }
              else {
                openHealthActions();
              }
            }
          });

          $healthControl.on('click', '.soda-scs-manager--health-action', function (event) {
            event.preventDefault();
            event.stopPropagation();
            runLifecycleAction(this);
          });

          $healthControl.on('keydown', function (event) {
            if (event.key === 'Escape') {
              closeHealthActions();
              $healthIcon.trigger('focus');
            }
          });
        }

        // Initial check.
        checkHealth();

        // Poll every 15 seconds.
        setInterval(checkHealth, 15000);
      });

      once('dashboardHealthLifecycleDismiss', 'html', context).forEach(function () {
        $(document).on('click.sodaScsHealthLifecycle', function (event) {
          if ($(event.target).closest('.soda-scs-manager--health-control').length) {
            return;
          }
          $('.soda-scs-manager--health-control.is-open').each(function () {
            $(this).removeClass('is-open');
            $(this).find('.soda-scs-manager--health-icon').attr('aria-expanded', 'false');
          });
        });
      });

      // Compact online/offline badges on project dashboard cards.
      once('dashboardProjectAppHealth', '.soda-scs-manager--project-card-app[data-entity-id]', context).forEach(function (row) {
        var $row = $(row);
        var entityId = $row.data('entity-id');
        var entityType = $row.data('entity-type');
        var $status = $row.find('.soda-scs-manager--project-card-app__status');
        var $dot = $row.find('.soda-scs-manager--project-card-app__dot');
        var $label = $row.find('.soda-scs-manager--project-card-app__label');

        if (!entityId || !entityType || !$status.length) {
          return;
        }

        var healthUrl = entityType === 'soda_scs_stack'
          ? Drupal.url('soda-scs-manager/health/stack/' + entityId)
          : Drupal.url('soda-scs-manager/health/component/' + entityId);

        function setProjectAppStatus(statusKey, labelText) {
          $status
            .attr('data-status', statusKey)
            .removeClass('bg-emerald-100 text-emerald-800 bg-amber-100 text-amber-800 bg-rose-100 text-rose-800');
          $dot.removeClass('bg-emerald-500 bg-amber-500 bg-rose-500');

          if (statusKey === 'online') {
            $status.addClass('bg-emerald-100 text-emerald-800');
            $dot.addClass('bg-emerald-500');
          }
          else if (statusKey === 'starting') {
            $status.addClass('bg-amber-100 text-amber-800');
            $dot.addClass('bg-amber-500');
          }
          else {
            $status.addClass('bg-rose-100 text-rose-800');
            $dot.addClass('bg-rose-500');
          }

          $label.text(labelText);
          $row.attr('data-health-status', statusKey);
        }

        function checkProjectAppHealth() {
          $.ajax({
            url: healthUrl,
            method: 'GET',
            timeout: 8000,
            dataType: 'json',
          }).done(function (data) {
            var status = '';
            if (data && data.status) {
              status = (data.status.status || '').toLowerCase();
              if (data.status.success === true && (!status || status === 'running' || status === 'healthy')) {
                setProjectAppStatus('online', Drupal.t('Online'));
                return;
              }
            }
            if (status === 'running' || status === 'healthy') {
              setProjectAppStatus('online', Drupal.t('Online'));
            }
            else if (status === 'starting') {
              setProjectAppStatus('starting', Drupal.t('Starting'));
            }
            else {
              setProjectAppStatus('offline', Drupal.t('Offline'));
            }
          }).fail(function () {
            setProjectAppStatus('offline', Drupal.t('Offline'));
          });
        }

        checkProjectAppHealth();
        setInterval(checkProjectAppHealth, 15000);
      });
    }
  };
})(jQuery, Drupal, once, drupalSettings);
