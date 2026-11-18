(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.sodaScsDetailsConfirmOverlay = {
    attach(context) {
      once('scsDetailsConfirmOverlay', '.scs-manager--details-confirm', context).forEach((container) => {
        const trigger = container.querySelector('.scs-manager--details-confirm-trigger');
        const dialog = container.querySelector('.scs-manager--details-confirm-dialog');
        const content = dialog.querySelector('.scs-manager--details-confirm-content');
        const loading = dialog.querySelector('.scs-manager--details-confirm-loading');
        const error = dialog.querySelector('.scs-manager--details-confirm-error');
        const closeButton = dialog.querySelector('.scs-manager--details-confirm-close');
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
          Drupal.detachBehaviors(content, drupalSettings, 'unload');
          content.replaceChildren();
          dialog.hidden = true;
          document.dispatchEvent(new CustomEvent('scs-manager:overlay-state-change'));
          trigger.focus();
        }

        async function openDialog() {
          if (request || !dialog.hidden) {
            return;
          }
          const currentRequest = new AbortController();
          request = currentRequest;
          dialog.hidden = false;
          loading.hidden = false;
          error.hidden = true;
          document.dispatchEvent(new CustomEvent('scs-manager:overlay-state-change'));
          closeButton.focus();

          try {
            const response = await fetch(trigger.href, {
              credentials: 'same-origin',
              signal: currentRequest.signal,
            });
            if (!response.ok) {
              throw new Error('Unable to load confirmation form');
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const form = page.querySelector(`form[data-drupal-selector="${container.dataset.confirmForm}"]`);
            if (!form || !form.querySelector('[name="form_token"]')) {
              throw new Error('Confirmation form not found');
            }
            if (request !== currentRequest) {
              return;
            }
            const importedForm = document.importNode(form, true);
            if (!importedForm.getAttribute('action')) {
              importedForm.setAttribute('action', trigger.href);
            }
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
              submit.classList.add('btn', 'btn--primary');
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