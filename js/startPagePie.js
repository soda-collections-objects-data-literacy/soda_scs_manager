/**
 * @file
 * Start page pie: swap center text and active segment by mouse position.
 * Hover is tracked on the whole pie so there are no gaps between segments (no flicker).
 */

(function (Drupal, once) {
  const VIEWBOX_SIZE = 200;
  const CENTER = 100;

  /**
   * Get segment name from angle in degrees (0 = right, 90 = bottom, clockwise).
   * Dashboard right (330°–30°), Admin bottom-left (30°–180°), Docs top-left (180°–330°).
   */
  function segmentFromAngle(angleDeg) {
    if (angleDeg >= 330 || angleDeg < 30) {
      return 'dashboard';
    }
    if (angleDeg >= 30 && angleDeg < 180) {
      return 'admin';
    }
    return 'docs';
  }

  function initPieHover(nav) {
    const svg = nav.querySelector('.soda-scs-manager--pie-svg');
    const labelEl = nav.querySelector('#soda-scs-manager--pie-center-label');
    const links = nav.querySelectorAll('.soda-scs-manager--pie-link[data-pie-label]');
    if (!svg || !labelEl || !links.length) {
      return;
    }
    const tspan = labelEl.querySelector('tspan');
    const defaultText = labelEl.getAttribute('data-pie-default') || 'SODa SCS';

    function setCenterText(text) {
      if (tspan) {
        tspan.textContent = text;
      }
    }

    function setActiveSegment(segment) {
      nav.setAttribute('data-active-segment', segment || '');
      const label = segment ? nav.querySelector(`.soda-scs-manager--pie-slice--${segment}`)?.closest('.soda-scs-manager--pie-link')?.getAttribute('data-pie-label') : null;
      setCenterText(label || defaultText);
    }

    function getAngleFromEvent(e) {
      const rect = svg.getBoundingClientRect();
      const x = ((e.clientX - rect.left) / rect.width) * VIEWBOX_SIZE;
      const y = ((e.clientY - rect.top) / rect.height) * VIEWBOX_SIZE;
      let angleRad = Math.atan2(y - CENTER, x - CENTER);
      let angleDeg = (angleRad * 180) / Math.PI;
      if (angleDeg < 0) {
        angleDeg += 360;
      }
      return angleDeg;
    }

    nav.addEventListener('mouseenter', (e) => {
      setActiveSegment(segmentFromAngle(getAngleFromEvent(e)));
    });
    nav.addEventListener('mousemove', (e) => {
      setActiveSegment(segmentFromAngle(getAngleFromEvent(e)));
    });
    nav.addEventListener('mouseleave', () => {
      setActiveSegment('');
    });

    links.forEach((link) => {
      const label = link.getAttribute('data-pie-label');
      if (!label) {
        return;
      }
      link.addEventListener('focus', () => {
        const slice = link.querySelector('[class*="soda-scs-manager--pie-slice--"]');
        const segment = slice?.classList.contains('soda-scs-manager--pie-slice--dashboard') ? 'dashboard' : slice?.classList.contains('soda-scs-manager--pie-slice--admin') ? 'admin' : 'docs';
        nav.setAttribute('data-active-segment', segment);
        setCenterText(label);
      });
      link.addEventListener('blur', () => {
        nav.removeAttribute('data-active-segment');
        setCenterText(defaultText);
      });
    });
  }

  Drupal.behaviors.startPagePie = {
    attach(context) {
      once('startPagePie', '.soda-scs-manager--pie-nav', context).forEach(initPieHover);
    },
  };
})(Drupal, once);
