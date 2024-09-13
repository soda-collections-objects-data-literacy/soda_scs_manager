(function ($, Drupal, once) {
  Drupal.behaviors.shadowServiceKeys = {
    attach: function (context, settings) {
      once('shadowServiceKeys', 'html', context).forEach(function () {
        const passwordElements = context.querySelectorAll('td.soda-scs-manager--service-password');
        if (passwordElements.length > 0) {
          const shadowValue = '************click_to_view************';
          passwordElements.forEach(function (element) {
            const passwordValue = element.textContent;
            element.textContent = shadowValue;
            element.addEventListener('click', function () {
              if (element.textContent === shadowValue) {
                element.textContent = passwordValue;
              } else {
                element.textContent = shadowValue;
              }
            });
          });
        }
      });
    }
  };
})(jQuery, Drupal, once);

