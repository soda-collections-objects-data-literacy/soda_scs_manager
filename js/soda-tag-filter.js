/**
 * @file
 * Contains the behavior for filtering applications by tags.
 */

(function (Drupal) {
  'use strict';

  /**
   * Behavior for tag filtering.
   */
  Drupal.behaviors.sodaTagFilter = {
    attach: function (context, settings) {
      // Get all tag filter buttons
      const filterButtons = context.querySelectorAll('.soda-tag-filter-button');

      // Initialize state
      const filterContainer = context.querySelector('.soda-tag-filter');
      if (!filterContainer) return;

      let activeTags = JSON.parse(filterContainer.dataset.activeTags || '[]');

      // Add click event to each filter button
      filterButtons.forEach(button => {
        button.addEventListener('click', function() {
          const tag = this.dataset.tag;

          // Toggle tag selection
          if (activeTags.includes(tag)) {
            // Remove tag
            activeTags = activeTags.filter(t => t !== tag);
            this.setAttribute('aria-pressed', 'false');
            this.querySelector('.soda-tag-remove').classList.add('hidden');
          } else {
            // Add tag
            activeTags.push(tag);
            this.setAttribute('aria-pressed', 'true');
            this.querySelector('.soda-tag-remove').classList.remove('hidden');
          }

          // Update active tags data attribute
          filterContainer.dataset.activeTags = JSON.stringify(activeTags);

          // Apply filtering
          applyFiltering(context, activeTags);
        });
      });

      /**
       * Apply filtering based on active tags.
       *
       * @param {HTMLElement} context - The context element.
       * @param {Array} activeTags - Array of active tag names.
       */
      function applyFiltering(context, activeTags) {
        // Get all card elements
        const cards = context.querySelectorAll('.soda-scs-manager--type--card');

        // If no active tags, show all cards
        if (activeTags.length === 0) {
          cards.forEach(card => {
            card.classList.remove('hidden-by-filter');
          });
          return;
        }

        // Filter cards based on active tags
        cards.forEach(card => {
          const cardTags = Array.from(card.querySelectorAll('.soda-scs-manager--card-tag'))
            .map(tagEl => tagEl.textContent.trim());

          // Check if the card has at least one of the active tags
          const hasMatchingTag = activeTags.some(tag => cardTags.includes(tag));

          if (hasMatchingTag) {
            card.classList.remove('hidden-by-filter');
          } else {
            card.classList.add('hidden-by-filter');
          }
        });
      }
    }
  };

})(Drupal);
