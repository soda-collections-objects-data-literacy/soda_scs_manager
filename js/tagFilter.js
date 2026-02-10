/**
 * @file
 * Contains the behavior for filtering applications by tags.
 */

(function (Drupal) {
  'use strict';

  /**
   * Behavior for tag filtering.
   */
  Drupal.behaviors.tagFilter = {
    attach: function (context, settings) {
      // Get all tag filter buttons.
      const filterButtons = context.querySelectorAll('.soda-scs-manager--tag-filter-button');

      // Initialize state.
      const filterContainer = context.querySelector('.soda-scs-manager--tag-filter');
      if (!filterContainer) {
        return;
      }

      let activeTags = JSON.parse(filterContainer.dataset.activeTags || '[]');

      // Add events to each filter button.
      filterButtons.forEach(button => {
        // Prevent duplicate binding.
        if (button.dataset.tagFilterInit === 'true') return;
        button.dataset.tagFilterInit = 'true';

        // Click handler.
        button.addEventListener('click', function (event) {
          // Prevent overlay link navigation and stop bubbling to card.
          if (event) {
            event.preventDefault();
            event.stopPropagation();
          }

          const tag = this.dataset.tag || (this.textContent || '').trim();
          if (!tag) return;

          const isActive = activeTags.includes(tag);

          // Toggle tag selection.
          if (isActive) {
            // Remove tag.
            activeTags = activeTags.filter(activeTag => activeTag !== tag);
          } else {
            // Add tag.
            activeTags.push(tag);
          }

          // Reflect state on all buttons for this tag.
          const matchingButtons = context.querySelectorAll('.soda-scs-manager--tag-filter-button[data-tag="' + CSS.escape(tag) + '"]');
          matchingButtons.forEach(matchBtn => {
            matchBtn.setAttribute('aria-pressed', String(!isActive));
            const removeIcon = matchBtn.querySelector('.soda-scs-manager--tag-remove');
            if (removeIcon) {
              if (isActive) {
                removeIcon.classList.add('hidden');
              } else {
                removeIcon.classList.remove('hidden');
              }
            }
          });

          // Update active tags data attribute.
          filterContainer.dataset.activeTags = JSON.stringify(activeTags);

          // Apply filtering.
          applyFiltering(context, activeTags);
        });

        // Keyboard accessibility: activate on Enter or Space.
        button.addEventListener('keydown', function (event) {
          const key = event.key;
          if (key === 'Enter' || key === ' ') {
            event.preventDefault();
            event.stopPropagation();
            this.click();
          }
        });
      });

      /**
       * Apply filtering based on active tags.
       *
       * @param {HTMLElement} context - The context element.
       * @param {Array} activeTags - Array of active tag names.
       */
      function applyFiltering(context, activeTags) {
        // Get all card elements.
        const cards = context.querySelectorAll('.soda-scs-manager--type--card');

        // Remove any existing empty-filter messages.
        context.querySelectorAll('.soda-scs-manager--empty-filter-message').forEach(function (el) {
          el.remove();
        });

        // If no active tags, show all cards.
        if (activeTags.length === 0) {
          cards.forEach(function (card) {
            card.classList.remove('hidden-by-filter');
          });
          return;
        }

        // Filter cards based on active tags.
        cards.forEach(function (card) {
          const cardTags = Array.from(card.querySelectorAll('.soda-scs-manager--card-tag'))
            .map(function (tagEl) { return tagEl.dataset.tag; });

          // Check if the card has at least one of the active tags.
          const hasMatchingTag = activeTags.some(function (tag) { return cardTags.includes(tag); });

          if (hasMatchingTag) {
            card.classList.remove('hidden-by-filter');
          } else {
            card.classList.add('hidden-by-filter');
          }
        });

        // Check each grid section for visible cards and show message if empty.
        const grids = context.querySelectorAll('.soda-scs-manager--view--grid');
        grids.forEach(function (grid) {
          const gridCards = grid.querySelectorAll('.soda-scs-manager--type--card');
          const visibleCards = Array.from(gridCards).filter(function (card) {
            return !card.classList.contains('hidden-by-filter');
          });

          if (gridCards.length > 0 && visibleCards.length === 0) {
            var tagLabel = activeTags.join(', ');
            var message = document.createElement('div');
            message.className = 'soda-scs-manager--empty-filter-message';
            message.innerHTML = '<p>' +
              Drupal.t('No <strong>@tags</strong> application found.', { '@tags': tagLabel }) +
              ' <a href="/soda-scs-manager/catalogue">' +
              Drupal.t('Go to the catalogue and create one!') +
              '</a></p>';
            grid.appendChild(message);
          }
        });
      }
    }
  };

})(Drupal);
