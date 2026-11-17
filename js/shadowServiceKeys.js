(function (Drupal, once) {
  Drupal.behaviors.shadowServiceKeys = {
    attach: function (context, settings) {
      once('shadowServiceKeys', 'html', context).forEach(function () {
        const passwordElements = context.querySelectorAll('.soda-scs-manager--service-password, .field--name-servicepassword .field__item');
        if (passwordElements.length > 0) {
          const shadowValue = Drupal.t('***click_to_view***');
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
                popup.className = 'scs-manager--copy-confirmation';
                // Only the click position stays inline; the look is theme CSS.
                popup.style.top = event.clientY + 'px';
                popup.style.left = event.clientX + 'px';
                popup.textContent = Drupal.t('Password copied to clipboard.');
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
})(Drupal, once);

