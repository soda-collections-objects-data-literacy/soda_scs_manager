(function ($, Drupal, once) {
  Drupal.behaviors.shadowServiceKeys = {
    attach: function (context, settings) {
      once('shadowServiceKeys', 'html', context).forEach(function () {
        const passwordElements = context.querySelectorAll('.soda-scs-manager--service-password, .field--name-servicepassword .field__item');
        if (passwordElements.length > 0) {
          const shadowValue = '************click_to_view************';
          passwordElements.forEach(function (element) {
            const passwordValue = element.textContent;
            element.textContent = shadowValue;
            element.addEventListener('click', function () {
              if (element.textContent === shadowValue) {
                const el = document.createElement('textarea');
                el.value = passwordValue;
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                document.body.removeChild(el);
                const popup = document.createElement('div');
                popup.style.position = 'absolute';
                popup.style.top = event.clientY + 'px';
                popup.style.left = event.clientX + 'px';
                popup.style.background = 'lightgreen';
                popup.style.padding = '5px';
                popup.style.borderRadius = '5px';
                popup.textContent = 'copied password to clipboard';
                document.body.appendChild(popup);
                setTimeout(function () {
                    document.body.removeChild(popup);
                }, 2000);
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

