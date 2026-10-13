/**
 * @file
 * Live availability check for project names, which are unique platform-wide.
 *
 * The server enforces the rule on submit (SodaScsProjectName constraint); this
 * only warns while typing. Works in the create-project wizard popup too,
 * because the popup re-attaches behaviors to the loaded form.
 */

(function (Drupal, once) {
  const DEBOUNCE_MS = 400;
  const MESSAGE_CLASS = 'soda-scs-manager--project-name-check';
  const ERROR_CLASS = 'form-item--error-message';

  /**
   * Shows or clears the message below the label input.
   *
   * @param {HTMLInputElement} input
   * @param {HTMLElement} message
   * @param {string} text
   */
  function showMessage(input, message, text) {
    message.textContent = text;
    message.hidden = text === '';
    message.classList.toggle(ERROR_CLASS, text !== '');
    input.setAttribute('aria-invalid', text === '' ? 'false' : 'true');
  }

  Drupal.behaviors.sodaScsProjectNameCheck = {
    attach(context) {
      once('scs-project-name-check', 'input[data-scs-project-name-check-url]', context).forEach((input) => {
        const checkUrl = input.getAttribute('data-scs-project-name-check-url');
        const projectId = input.getAttribute('data-scs-project-id') || '';
        const message = document.createElement('div');
        message.className = MESSAGE_CLASS;
        message.id = `${input.id || 'scs-project-label'}--name-check`;
        message.setAttribute('aria-live', 'polite');
        message.hidden = true;
        input.insertAdjacentElement('afterend', message);
        const describedBy = input.getAttribute('aria-describedby');
        input.setAttribute('aria-describedby', describedBy ? `${describedBy} ${message.id}` : message.id);

        let timer = null;
        let controller = null;

        async function runCheck() {
          if (controller) {
            controller.abort();
          }
          const label = input.value.trim();
          if (label === '') {
            showMessage(input, message, '');
            return;
          }
          controller = new AbortController();
          const url = new URL(checkUrl, window.location.origin);
          url.searchParams.set('label', label);
          if (projectId) {
            url.searchParams.set('exclude', projectId);
          }
          try {
            const response = await fetch(url.toString(), {
              credentials: 'same-origin',
              signal: controller.signal,
            });
            if (!response.ok) {
              showMessage(input, message, '');
              return;
            }
            const data = await response.json();
            showMessage(
              input,
              message,
              data.available
                ? ''
                : String(data.error || Drupal.t('A project with this or a very similar name already exists. Please choose a different name.')),
            );
          }
          catch (error) {
            if (!error || error.name !== 'AbortError') {
              showMessage(input, message, '');
            }
          }
        }

        input.addEventListener('input', () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(runCheck, DEBOUNCE_MS);
        });
        input.addEventListener('blur', () => {
          window.clearTimeout(timer);
          runCheck();
        });
      });
    },
  };
})(Drupal, once);
