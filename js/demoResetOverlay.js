(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.sodaScsDemoResetOverlay = {
    attach(context) {
      once('sodaScsDemoResetOverlay', '.scs-manager--demo-reset', context).forEach((container) => {
        const trigger = container.querySelector('.scs-manager--demo-reset-link');
        const dialog = container.querySelector('.scs-manager--demo-reset-dialog');
        const content = dialog.querySelector('.scs-manager--demo-reset-content');
        const loading = dialog.querySelector('.scs-manager--demo-reset-loading');
        const error = dialog.querySelector('.scs-manager--demo-reset-error');
        const closeButton = dialog.querySelector('.scs-manager--demo-reset-close');
        const mainContent = document.querySelector('.dashboard-main');
        let request = null;
        let isSubmitting = false;

        if (mainContent && !mainContent.contains(dialog)) {
          mainContent.appendChild(dialog);
        }

        function closeDialog() {
          if (isSubmitting) {
            return;
          }
          if (request) {
            request.abort();
            request = null;
          }
          dialog.hidden = true;
          document.dispatchEvent(new CustomEvent('scs-manager:overlay-state-change'));
          content.replaceChildren();
          trigger.focus();
        }

        async function openDialog() {
          const currentRequest = new AbortController();
          request = currentRequest;
          dialog.hidden = false;
          document.dispatchEvent(new CustomEvent('scs-manager:overlay-state-change'));
          loading.hidden = false;
          error.hidden = true;
          closeButton.focus();

          try {
            const response = await fetch(trigger.href, {
              credentials: 'same-origin',
              signal: currentRequest.signal,
            });
            if (!response.ok) {
              throw new Error('Unable to load demo reset form');
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const form = page.querySelector('form[data-drupal-selector="soda-scs-demo-project-reset-form"]');
            if (!form) {
              throw new Error('Demo reset form not found');
            }
            if (request !== currentRequest) {
              return;
            }
            const importedForm = document.importNode(form, true);
            importedForm.addEventListener('submit', (event) => {
              if (isSubmitting) {
                event.preventDefault();
              }
              else {
                isSubmitting = true;
              }
            });
            const actions = importedForm.querySelector('.form-actions');
            const cancel = importedForm.querySelector('.dialog-cancel');
            const submit = importedForm.querySelector('[type="submit"]');
            if (actions) {
              actions.classList.add('project-card__delete-confirmation__actions');
            }
            if (cancel) {
              cancel.classList.add('btn', 'btn--secondary');
              cancel.addEventListener('click', (event) => {
                event.preventDefault();
                closeDialog();
              });
            }
            if (submit) {
              submit.classList.add('btn', 'btn--primary', 'project-card__delete-confirmation__confirm');
            }
            content.replaceChildren(importedForm);
            Drupal.attachBehaviors(content);
            loading.hidden = true;
            request = null;
          }
          catch (exception) {
            if (request !== currentRequest) {
              return;
            }
            loading.hidden = true;
            error.hidden = false;
            request = null;
          }
        }

        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          openDialog();
        });
        closeButton.addEventListener('click', closeDialog);
        dialog.addEventListener('click', (event) => {
          if (event.target === dialog || event.target.classList.contains('project-card__delete-confirmation__backdrop')) {
            closeDialog();
          }
        });
        dialog.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog();
          }
          else if (event.key === 'Tab') {
            const focusable = Array.from(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
              .filter((element) => element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (first && event.shiftKey && document.activeElement === first) {
              event.preventDefault();
              last.focus();
            }
            else if (last && !event.shiftKey && document.activeElement === last) {
              event.preventDefault();
              first.focus();
            }
          }
        });
      });
    },
  };
})(Drupal, once);