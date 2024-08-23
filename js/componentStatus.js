(function ($, Drupal, once) {
  Drupal.behaviors.sodaScsManagerComponentStatus = {
    attach: function (context, settings) {
      once('sodaScsManagerComponentStatus', 'html', context).forEach(function () {
        $('.start').on('click', function() {
          console.log('Start button clicked');
          // Add your start logic here
        });

        $('.stop').on('click', function() {
          console.log('Stop button clicked');
          // Add your stop logic here
        });

        $('.restart').on('click', function() {
          console.log('Restart button clicked');
          // Add your restart logic here
        });
      });
    }
  };
})(jQuery, Drupal, once);
