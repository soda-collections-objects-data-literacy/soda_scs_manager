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
    var member = menu.closest('.project-member');
    var panel = member && member.querySelector('.project-member__roles-panel');
    var trigger = menu.querySelector('.project-member__roles-trigger');
    if (panel) {
      panel.hidden = true;
    }
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
    }
  }

  function saveRole(select, status) {
    var newRole = select.value;
    status.classList.remove('project-member__roles-status--error');
    status.textContent = Drupal.t('Saving…');

    return fetchCsrfToken()
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
        }).then(function (data) {
          if (!response.ok || !data.success) {
            throw new Error(data.message || '');
          }
          return data;
        });
      })
      .then(function (data) {
        select.setAttribute('data-current-role', newRole);
        status.textContent = data.message || Drupal.t('Role saved.');
        return true;
      })
      .catch(function (error) {
        status.classList.add('project-member__roles-status--error');
        status.textContent = (error && error.message) || Drupal.t('The role could not be saved. Please try again.');
        return false;
      });
  }

  Drupal.behaviors.sodaScsProjectMemberRoles = {
    attach: function (context) {
      once('sodaScsProjectMemberRoles', '.project-member__roles-menu', context).forEach(function (menu) {
        var member = menu.closest('.project-member');
        var trigger = menu.querySelector('.project-member__roles-trigger');
        var panel = member && member.querySelector('.project-member__roles-panel');
        var status = panel && panel.querySelector('.project-member__roles-status');
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

        member.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && !panel.hidden) {
            // Close only the panel, not the surrounding team overlay.
            event.stopPropagation();
            closeRolePanel(menu);
            trigger.focus();
          }
        });

        document.addEventListener('click', function (event) {
          if (!panel.hidden && !member.contains(event.target)) {
            closeRolePanel(menu);
          }
        });

      });

      once('sodaScsProjectMemberRoleSave', '#project-members-edit-overlay', context).forEach(function (overlay) {
        var save = overlay.querySelector('.project-members-edit-overlay__save');
        var summary = overlay.querySelector('.project-members-edit-overlay__save-status');
        if (!save || !summary) {
          return;
        }

        var selects = Array.from(overlay.querySelectorAll('.project-member__role-select'));
        var changed = function (select) {
          return select.value !== select.getAttribute('data-current-role');
        };

        overlay.addEventListener('change', function (event) {
          if (selects.includes(event.target)) {
            save.disabled = !selects.some(changed);
            summary.textContent = '';
            summary.classList.remove('project-member__roles-status--error');
          }
        });

        save.addEventListener('click', async function () {
          var pending = selects.filter(changed);
          if (!pending.length) {
            return;
          }
          save.disabled = true;
          selects.forEach(function (select) { select.disabled = true; });
          summary.classList.remove('project-member__roles-status--error');
          summary.textContent = Drupal.t('Saving…');
          var failed = false;
          for (var select of pending) {
            var status = select.closest('.project-member__roles-panel').querySelector('.project-member__roles-status');
            if (!await saveRole(select, status)) {
              failed = true;
            }
          }
          selects.forEach(function (select) { select.disabled = false; });
          summary.classList.toggle('project-member__roles-status--error', failed);
          summary.textContent = failed
            ? Drupal.t('Some role changes could not be saved. Please try again.')
            : Drupal.t('Changes saved.');
          save.disabled = !selects.some(changed);
        });
      });
    }
  };
})(Drupal, once);
