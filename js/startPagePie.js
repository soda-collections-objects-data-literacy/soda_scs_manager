/**
 * @file
 * Start page pie: swap center circle text on segment hover.
 */

(function (Drupal, once) {
  function initPieHover(nav) {
    const labelEl = nav.querySelector('#soda-scs-manager--pie-center-label');
    const links = nav.querySelectorAll('.soda-scs-manager--pie-link[data-pie-label]');
    if (!labelEl || !links.length) {
      return;
    }
    const tspan = labelEl.querySelector('tspan');
    const defaultText = labelEl.getAttribute('data-pie-default') || 'SODa SCS';

    function setCenterText(text) {
      if (tspan) {
        tspan.textContent = text;
      }
    }

    links.forEach((link) => {
      const label = link.getAttribute('data-pie-label');
      if (!label) {
        return;
      }
      link.addEventListener('mouseenter', () => setCenterText(label));
      link.addEventListener('focus', () => setCenterText(label));
      link.addEventListener('mouseleave', () => setCenterText(defaultText));
      link.addEventListener('blur', () => setCenterText(defaultText));
    });
  }

  Drupal.behaviors.startPagePie = {
    attach(context) {
      once('startPagePie', '.soda-scs-manager--pie-nav', context).forEach(initPieHover);
    },
  };
})(Drupal, once);
