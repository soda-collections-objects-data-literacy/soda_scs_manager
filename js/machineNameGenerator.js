/**
 * @file
 * JavaScript to generate machine name from label.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.machineNameGenerator = {
    attach: function (context, settings) {
      // Find the source (label) and target (machine name) fields
      const $source = $('.soda-scs-manager--machine-name-source', context);
      const $target = $('.soda-scs-manager--machine-name-target', context);

      if ($source.length && $target.length) {
        // Process the label to create a machine name
        const createMachineName = function (label) {
          return label.toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-') // Replace non-alphanumeric characters with underscore
            .replace(/^[^a-z]+/, '')      // Remove non-alphabetic characters from the beginning
            .replace(/-+$/, '')           // Remove trailing underscores
            .substring(0, 32);            // Limit length to 32 characters
        };

        // Use once utility properly
        once('machine-name', $source, context).forEach(function(element) {
          $(element).on('keyup change', function () {
            const label = $(this).val();
            const machineName = createMachineName(label);
            $target.val(machineName);
          });
        });

        // Initial generation if label has a value
        if ($source.val()) {
          $target.val(createMachineName($source.val()));
        }
      }
    }
  };

})(jQuery, Drupal, once);
