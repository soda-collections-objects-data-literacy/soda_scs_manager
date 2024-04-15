(function ($, Drupal, once) {
  'use strict';
  /**
   * Implements collapsing of individual pathbuilder rows using a caret
   */
  Drupal.behaviors.accountOptions = {
    attach: function (context, settings) {
      once('accountOptions', '#wcam--table', context).forEach(function () {
        $('.wcam--select').change(function () {
          let selectedOption = $(this).val();
          let aid = $(this).closest('tr').find('.wcam--row--account-id').text().trim();
          switch (selectedOption) {
            case 'delete':
              // Construct the URL for the delete route.
              let deleteUrl = Drupal.url('wisski-cloud-account-manager/delete/' + aid);
              // Redirect to the delete route.
              window.location.href = deleteUrl;
              break;

            case 'edit':
              console.log('Edit:', aid);
              break;

            case 'provise':
              // Construct the URL for the provise route.
              let proviseUrl = Drupal.url('wisski-cloud-account-manager/provise/' + aid);
              // Redirect to the provise route.
              window.location.href = proviseUrl;
              break;

            case 'purge':
              // Construct the URL for the purge route.
              let purgeUrl = Drupal.url('wisski-cloud-account-manager/purge/' + aid);
              // Redirect to the purge route.
              window.location.href = purgeUrl;
              break;

            case 'validate':
              // Construct the URL for the purge route.
              let validateUrl = Drupal.url('wisski-cloud-account-manager/force-validate/' + aid);
              // Redirect to the valide route.
              window.location.href = validateUrl;
              break;

            default:
              console.log('Eine andere Option wurde ausgewählt.');
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
