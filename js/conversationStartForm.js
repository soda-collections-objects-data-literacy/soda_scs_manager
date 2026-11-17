(function (Drupal, once, drupalSettings) {
  const TOKEN_ALL = 'all';

  function settings() {
    return (drupalSettings.sodaScsManager && drupalSettings.sodaScsManager.conversationRecipients) || {
      allLabel: Drupal.t('All'),
      projects: {},
      users: {},
    };
  }

  function parseTokens(raw) {
    return String(raw || '')
      .split(',')
      .map(function (token) {
        return token.trim();
      })
      .filter(Boolean);
  }

  function writeTokens(input, tokens) {
    input.value = tokens.join(',');
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function labelForToken(token) {
    const config = settings();
    if (token === TOKEN_ALL) {
      return config.allLabel;
    }
    if (token.indexOf('u:') === 0) {
      return config.users[token.slice(2)] || token;
    }
    if (token.indexOf('p:') === 0) {
      return config.projects[token.slice(2)] || token;
    }
    return token;
  }

  function renderChips(form) {
    const chips = form.querySelector('[data-scs-recipient-chips]');
    const tokensInput = form.querySelector('.scs-manager--conversation-start__tokens');
    if (!chips || !tokensInput) {
      return;
    }
    const tokens = parseTokens(tokensInput.value);
    chips.replaceChildren();
    tokens.forEach(function (token) {
      const chip = document.createElement('span');
      chip.className = 'scs-manager--conversation-start__chip';
      chip.dataset.token = token;

      const label = document.createElement('span');
      label.className = 'scs-manager--conversation-start__chip-label';
      label.textContent = labelForToken(token);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'scs-manager--conversation-start__chip-remove';
      remove.setAttribute('aria-label', Drupal.t('Remove @label', { '@label': label.textContent }));
      remove.textContent = '×';
      remove.addEventListener('click', function () {
        writeTokens(
          tokensInput,
          parseTokens(tokensInput.value).filter(function (current) {
            return current !== token;
          }),
        );
        renderChips(form);
      });

      chip.appendChild(label);
      chip.appendChild(remove);
      chips.appendChild(chip);
    });
  }

  function addToken(form, token) {
    const tokensInput = form.querySelector('.scs-manager--conversation-start__tokens');
    if (!tokensInput || !token) {
      return;
    }
    let tokens = parseTokens(tokensInput.value);
    if (token === TOKEN_ALL) {
      tokens = tokens.filter(function (current) {
        return current.indexOf('u:') !== 0;
      });
    }
    else if (token.indexOf('u:') === 0) {
      tokens = tokens.filter(function (current) {
        return current !== TOKEN_ALL;
      });
    }
    if (tokens.indexOf(token) === -1) {
      tokens.push(token);
    }
    writeTokens(tokensInput, tokens);
    renderChips(form);
  }

  function tokenFromPicker(select) {
    const value = select.value;
    if (!value) {
      return '';
    }
    if (select.getAttribute('data-scs-recipient-kind') === 'project') {
      return 'p:' + value;
    }
    if (value === TOKEN_ALL) {
      return TOKEN_ALL;
    }
    return 'u:' + value;
  }

  function clearTokens(form) {
    const tokensInput = form.querySelector('.scs-manager--conversation-start__tokens');
    if (!tokensInput) {
      return;
    }
    writeTokens(tokensInput, []);
    renderChips(form);
  }

  Drupal.behaviors.sodaScsConversationStartForm = {
    attach: function (context) {
      once('scs-conversation-start', '.scs-manager--conversation-start', context).forEach(function (form) {
        renderChips(form);

        form.querySelectorAll('.scs-manager--conversation-start__picker').forEach(function (select) {
          select.addEventListener('change', function () {
            const token = tokenFromPicker(select);
            if (token) {
              addToken(form, token);
            }
            select.value = '';
          });
        });

        form.querySelectorAll('input[name="mode"]').forEach(function (radio) {
          radio.addEventListener('change', function () {
            clearTokens(form);
          });
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
