/**
 * @file
 * JavaScript for Keycloak registration form.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.keycloakRegistration = {
    attach: function (context, settings) {
      // Add form class for styling.
      $('#keycloak-user-registration-form', context).once('keycloak-form').each(function () {
        $(this).addClass('keycloak-user-registration-form');
      });

      // Add form class for admin approval form.
      $('#keycloak-user-approval-form', context).once('keycloak-approval-form').each(function () {
        $(this).addClass('keycloak-user-approval-form');
      });

      // Password strength indicator.
      $('#edit-password-pass1', context).once('password-strength').each(function () {
        $(this).on('keyup', function () {
          var password = $(this).val();
          var strength = 0;

          if (password.length >= 8) {
            strength += 1;
          }

          if (password.match(/[a-z]/) && password.match(/[A-Z]/)) {
            strength += 1;
          }

          if (password.match(/\d/)) {
            strength += 1;
          }

          if (password.match(/[^a-zA-Z\d]/)) {
            strength += 1;
          }

          var strengthBar = $('.password-strength-bar');
          if (strengthBar.length === 0) {
            $(this).after('<div class="password-strength-bar"><div class="bar"></div></div>');
            strengthBar = $('.password-strength-bar');
          }

          var barWidth = (strength / 4) * 100;
          var barColor = 'red';

          if (strength >= 2) {
            barColor = 'yellow';
          }

          if (strength >= 3) {
            barColor = 'green';
          }

          strengthBar.find('.bar').css({
            'width': barWidth + '%',
            'background-color': barColor
          });
        });
      });
    }
  };

}(jQuery, Drupal));
