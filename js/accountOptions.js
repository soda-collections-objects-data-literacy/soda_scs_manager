(function ($, Drupal, once) {
  'use strict';
  /**
   * Implements collapsing of individual pathbuilder rows using a caret
   */
  Drupal.behaviors.accountOptions = {
    attach: function (context, settings) {
      once('accountOptions', '#wcam--table', context).forEach(function (form) {
        $('.wcam--select').change(function () {
          console.log($(this))
          let selectedOption = $(this).val();
          let itemId = $(this).closest('tr').find('.wcam--row--item-id').text();
          switch (selectedOption) {
            case 'delete':
              // Führen Sie hier Ihren JavaScript-Code für 'delete' aus.

              console.log('delete:', itemId);
              break;

            case 'edit':
              // Führen Sie hier Ihren JavaScript-Code für 'edit' aus.
              console.log('Edit:', itemId);
              break;

            case 'provise':
              // Führen Sie hier Ihren JavaScript-Code für 'provise' aus.
              console.log('Provise', itemId);
              break;

            case 'validate':
              // Führen Sie hier Ihren JavaScript-Code für 'validate' aus.
              console.log('Die Option "validate" wurde ausgewählt.');
              break;

            default:
              console.log('Eine andere Option wurde ausgewählt.');
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
