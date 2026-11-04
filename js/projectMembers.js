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

  var csrfTokenPromise = null;

  function fetchCsrfToken() {
    if (!csrfTokenPromise) {
      csrfTokenPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('CSRF token request failed');
          }
          return response.text();
        })
        .catch(function (error) {
          csrfTokenPromise = null;
          throw error;
        });
    }
    return csrfTokenPromise;
  }

  function closeRolePanel(menu) {
    var panel = menu.querySelector('.project-member__roles-panel');
    var trigger = menu.querySelector('.project-member__roles-trigger');
    if (panel) {
      panel.hidden = true;
    }
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
    }
  }

  function saveRole(select, status) {
    var previousRole = select.getAttribute('data-current-role');
    var newRole = select.value;
    select.disabled = true;
    status.classList.remove('project-member__roles-status--error');
    status.textContent = Drupal.t('Saving…');

    fetchCsrfToken()
      .then(function (token) {
        return fetch(select.getAttribute('data-role-url'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token
          },
          body: JSON.stringify({ role: newRole })
        });
      })
      .then(function (response) {
        return response.json().catch(function () {
          return { success: false, message: '' };
        });
      })
      .then(function (data) {
        if (!data.success) {
          throw new Error(data.message || '');
        }
        select.setAttribute('data-current-role', newRole);
        status.textContent = data.message || Drupal.t('Role saved.');
      })
      .catch(function (error) {
        // The server keeps the previous role on failure, so the select must too.
        select.value = previousRole;
        status.classList.add('project-member__roles-status--error');
        status.textContent = (error && error.message) || Drupal.t('The role could not be saved. Please try again.');
      })
      .finally(function () {
        select.disabled = false;
      });
  }

  Drupal.behaviors.sodaScsProjectMemberRoles = {
    attach: function (context) {
      once('sodaScsProjectMemberRoles', '.project-member__roles-menu', context).forEach(function (menu) {
        var trigger = menu.querySelector('.project-member__roles-trigger');
        var panel = menu.querySelector('.project-member__roles-panel');
        var status = menu.querySelector('.project-member__roles-status');
        if (!trigger || !panel || !status) {
          return;
        }

        trigger.addEventListener('click', function (event) {
          event.preventDefault();
          var willOpen = panel.hidden;
          document.querySelectorAll('.project-member__roles-menu').forEach(closeRolePanel);
          panel.hidden = !willOpen;
          trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          if (willOpen) {
            status.textContent = '';
            var firstSelect = panel.querySelector('select');
            if (firstSelect) {
              firstSelect.focus();
            }
          }
        });

        menu.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && !panel.hidden) {
            // Close only the panel, not the surrounding team overlay.
            event.stopPropagation();
            closeRolePanel(menu);
            trigger.focus();
          }
        });

        document.addEventListener('click', function (event) {
          if (!panel.hidden && !menu.contains(event.target)) {
            closeRolePanel(menu);
          }
        });

        panel.querySelectorAll('.project-member__role-select').forEach(function (select) {
          select.addEventListener('change', function () {
            saveRole(select, status);
          });
        });
      });
    }
  };
})(Drupal, once);
