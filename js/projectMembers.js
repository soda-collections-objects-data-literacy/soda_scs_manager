(function (Drupal, once) {
  Drupal.behaviors.sodaScsProjectMembers = {
    attach: function (context) {
      once('sodaScsProjectMembers', '.soda-scs-manager--project-members', context).forEach(function (root) {
        var button = root.querySelector('.soda-scs-manager--add-member-button');
        var formWrap = root.querySelector('.soda-scs-manager--add-member-form');
        if (!button || !formWrap) {
          return;
        }

        button.addEventListener('click', function () {
          var isHidden = formWrap.classList.contains('hidden');
          formWrap.classList.toggle('hidden', !isHidden);
          button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
          if (isHidden) {
            var input = formWrap.querySelector('input[type="text"], input:not([type="hidden"])');
            if (input) {
              input.focus();
            }
          }
        });

        if (formWrap.querySelector('.error, .form-item--error')) {
          formWrap.classList.remove('hidden');
          button.setAttribute('aria-expanded', 'true');
        }
      });
    }
  };
})(Drupal, once);
