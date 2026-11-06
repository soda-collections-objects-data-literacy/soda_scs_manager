/**
 * @file
 * Guided onboarding tour across the dashboard, project and application pages.
 *
 * The step state lives in sessionStorage so the tour survives the page loads
 * between steps. One dimmed backdrop is clipped to cut-outs around the
 * highlighted elements: the dim area blocks clicks, the cut-outs stay usable.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const STATE_KEY = 'scsOnboardingTour';
  // Private-use characters stand in for placeholders inside translated sentences.
  const SLOT_CODE_BASE = 0xE000;
  const SLOT_SPLIT_PATTERN = /([\uE000-\uE0FF])/;
  const HOLE_PADDING = 8;
  const HOLE_RADIUS = 12;
  const CARD_GAP = 18;
  const VIEWPORT_MARGIN = 16;
  const FLOATING_BOTTOM_OFFSET = 24;
  const CARET_EDGE_OFFSET = 24;
  const NARROW_VIEWPORT_WIDTH = 600;
  const LARGE_TARGET_VIEWPORT_SHARE = 0.6;
  const COMPLETE_TIMEOUT_MS = 2000;
  const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const PAGE_PROBES = {
    dashboard: '.dashboard__projects',
    project: '.project--left .project__applications',
    application: '.application__details-left',
  };
  const BLOCK_CLASSES = {
    heading: 'scs-onboarding__heading h3',
    lead: 'scs-onboarding__lead h5',
    text: 'scs-onboarding__text',
    hint: 'scs-onboarding__hint h6',
  };

  let ui = null;
  let cachedSteps = null;

  const config = () => drupalSettings.sodaScsManager?.onboardingTour || {};
  const findAll = (selector) => Array.from(document.querySelectorAll(selector));
  const clamp = (value, min, max) => Math.min(Math.max(value, min), Math.max(min, max));
  const slot = (index) => String.fromCharCode(SLOT_CODE_BASE + index);

  function readState() {
    try {
      return JSON.parse(window.sessionStorage.getItem(STATE_KEY));
    }
    catch (error) {
      return null;
    }
  }

  function writeState(state) {
    try {
      window.sessionStorage.setItem(STATE_KEY, JSON.stringify(state));
    }
    catch (error) {
      // Without storage the tour still works until the next page load.
    }
  }

  function clearState() {
    try {
      window.sessionStorage.removeItem(STATE_KEY);
    }
    catch (error) {
      // Nothing stored, nothing to clear.
    }
  }

  function currentPage() {
    return Object.keys(PAGE_PROBES).find((page) => document.querySelector(PAGE_PROBES[page])) || null;
  }

  function pageUrl(page, state) {
    if (page === 'dashboard') {
      return config().dashboardUrl || '';
    }
    return (state.urls && state.urls[page]) || '';
  }

  function findDocumentationLink() {
    // The documentation link is the only sidebar link carrying the book icon.
    return document.querySelector('.dashboard-sidebar__secondary-nav a:has(svg[viewBox="0 0 640 640"])');
  }

  /**
   * Appends translated text, replacing slot characters with built nodes.
   */
  function appendRichText(parent, text, slots) {
    text.split(SLOT_SPLIT_PATTERN).forEach((part) => {
      if (part === '') {
        return;
      }
      const build = part.length === 1 ? slots[part.charCodeAt(0) - SLOT_CODE_BASE] : null;
      parent.appendChild(build ? build() : document.createTextNode(part));
    });
  }

  function accentNode(label, tone, isExternal) {
    const element = document.createElement('span');
    element.className = 'scs-onboarding__accent scs-onboarding__accent--' + tone;
    if (isExternal) {
      element.classList.add('link--external');
    }
    element.textContent = label;
    return element;
  }

  function documentationIconNode() {
    const wrapper = document.createElement('span');
    wrapper.className = 'scs-onboarding__inline-icon';
    wrapper.setAttribute('role', 'img');
    wrapper.setAttribute('aria-label', Drupal.t('Documentation'));
    const icon = findDocumentationLink()?.querySelector('svg');
    if (icon) {
      wrapper.appendChild(icon.cloneNode(true));
    }
    else {
      wrapper.textContent = Drupal.t('Documentation');
    }
    return wrapper;
  }

  function getSteps() {
    if (!cachedSteps) {
      cachedSteps = buildSteps();
    }
    return cachedSteps;
  }

  function buildSteps() {
    const demoCard = () => document.querySelector('.dashboard__projects .project--card[data-demo-project]')
      || document.querySelector('.dashboard__projects .project--card');
    const detailsLink = () => document.querySelector('.project__applications .application--card:not(.application--card--add) .application__details-link');
    const projectTile = () => document.querySelector('.project__applications .application--card:not(.application--card--add) .application__core');
    const subsystemTile = () => document.querySelector('.application__details-card--applications .application--card .application__core');
    const outline = (element, tone) => (element ? [{ element, tone }] : []);
    const backAction = () => ({ label: Drupal.t('One step back'), tone: 'secondary', command: 'back' });
    const nextAction = () => ({ label: Drupal.t('Next'), tone: 'primary', command: 'next' });

    return [
      {
        page: 'dashboard',
        blocks: [
          { kind: 'heading', text: Drupal.t('Hi there!') },
          { kind: 'text', text: Drupal.t('Want me to show you around?') },
        ],
        actions: [
          { label: Drupal.t('No, I already know everything'), tone: 'secondary', command: 'skip' },
          { label: Drupal.t('Yes, Please'), tone: 'primary', command: 'next' },
        ],
      },
      {
        page: 'dashboard',
        blocks: [
          { kind: 'heading', text: Drupal.t("Great, so let's go!") },
          { kind: 'lead', text: Drupal.t('This is your dashboard') },
          { kind: 'text', text: Drupal.t('Here you can see all your projects and add new ones.') },
        ],
        actions: [{ label: Drupal.t('Got it!'), tone: 'primary', command: 'next' }],
      },
      {
        page: 'dashboard',
        blocks: [
          { kind: 'lead', text: Drupal.t('These are your projects') },
          { kind: 'text', text: Drupal.t('We already created a Demo Project for you.') },
          { kind: 'hint', text: Drupal.t('Click on it to explore it!') },
        ],
        actions: [backAction(), nextAction()],
        targets: () => {
          const card = demoCard();
          return card ? [card] : [];
        },
        capture: (state, targets) => {
          const link = targets[0].querySelector('.project-card__link');
          if (link) {
            state.urls.project = link.href;
          }
        },
      },
      {
        page: 'project',
        blocks: [
          { kind: 'heading', text: Drupal.t('This is your project!') },
          { kind: 'text', text: Drupal.t("There is so much to explore, let's just dive right in!") },
        ],
        actions: [backAction(), nextAction()],
      },
      {
        page: 'project',
        blocks: [
          { kind: 'lead', text: Drupal.t('Your project details') },
          { kind: 'text', text: Drupal.t('Create notes for each other and manage your team.') },
        ],
        actions: [backAction(), nextAction()],
        targets: () => findAll('.project--right .project__note, .project--right .project__members'),
      },
      {
        page: 'project',
        blocks: [
          { kind: 'lead', text: Drupal.t('Organize your files') },
          { kind: 'text', text: Drupal.t("Check your most recent files or hop straight into your project's file system.") },
          {
            kind: 'hint',
            text: Drupal.t('Click the @button to get to the file system.', { '@button': slot(0) }),
            slots: [() => accentNode(Drupal.t('button'), 'blue', true)],
          },
        ],
        actions: [backAction(), nextAction()],
        targets: () => findAll('.project--right .project__files'),
        accents: () => outline(document.querySelector('.project__files .project__files__button'), 'blue'),
      },
      {
        page: 'project',
        blocks: [
          { kind: 'lead', text: Drupal.t('Now the most important, your applications!') },
          { kind: 'text', text: Drupal.t('Get an overview, add or inspect your apps!') },
          {
            kind: 'hint',
            text: Drupal.t('Jump right in by clicking on the @tile.', { '@tile': slot(0) }),
            slots: [() => accentNode(Drupal.t('tile'), 'blue', true)],
          },
          {
            kind: 'hint',
            text: Drupal.t('Or click the details @button to get to the application details.', { '@button': slot(0) }),
            slots: [() => accentNode(Drupal.t('button'), 'green', false)],
          },
        ],
        actions: [backAction(), nextAction()],
        targets: () => findAll('.project--left .project__applications'),
        available: () => Boolean(detailsLink()),
        accents: () => outline(projectTile(), 'blue').concat(outline(detailsLink(), 'green')),
        capture: (state) => {
          state.urls.application = detailsLink().href;
        },
      },
      {
        page: 'application',
        blocks: [
          { kind: 'heading', text: Drupal.t('Your Application Details') },
          { kind: 'text', text: Drupal.t("Inspect your application's health status and check on subsystems.") },
        ],
        actions: [backAction(), nextAction()],
      },
      {
        page: 'application',
        blocks: [
          { kind: 'lead', text: Drupal.t('Your application details') },
          { kind: 'text', text: Drupal.t('Everything you need to know about your application.') },
        ],
        actions: [backAction(), nextAction()],
        targets: () => findAll('.application__details-card--general-information, .application__details-card--version'),
      },
      {
        page: 'application',
        blocks: [
          { kind: 'hint', text: Drupal.t('Your subsystems') },
          { kind: 'text', text: Drupal.t('Manage them directly from their own dashboards!') },
          {
            kind: 'lead',
            text: Drupal.t('Jump right in by clicking on the @tile.', { '@tile': slot(0) }),
            slots: [() => accentNode(Drupal.t('tile'), 'blue', true)],
          },
        ],
        actions: [backAction(), nextAction()],
        targets: () => findAll('.application__details-card--applications'),
        available: () => Boolean(subsystemTile()),
        accents: () => outline(subsystemTile(), 'blue'),
      },
      {
        page: 'any',
        blocks: [
          { kind: 'heading', text: Drupal.t("That's it!") },
          { kind: 'text', text: Drupal.t('Have fun exploring!') },
          {
            kind: 'text',
            text: Drupal.t('If you have further questions, you can always go to the documentation by clicking on @icon or navigating there via the menu!', { '@icon': slot(0) }),
            slots: [documentationIconNode],
          },
          { kind: 'text', text: Drupal.t("I'll take you back to your dashboard now!") },
        ],
        actions: [backAction(), { label: Drupal.t('Done'), tone: 'primary', command: 'finish' }],
        targets: () => {
          const link = findDocumentationLink();
          return link ? [link] : [];
        },
        optionalTargets: true,
      },
    ];
  }

  function isStepUsable(step, targets) {
    if (step.available) {
      return step.available();
    }
    return !step.targets || step.optionalTargets === true || targets.length > 0;
  }

  /**
   * First step in the direction of travel that can be shown or navigated to.
   */
  function findReachableStepIndex(from, direction, state, page) {
    const steps = getSteps();
    for (let index = from; index >= 0 && index < steps.length; index += direction) {
      const step = steps[index];
      if (step.page === 'any' || step.page === page || pageUrl(step.page, state) !== '') {
        return index;
      }
    }
    return direction > 0 ? steps.length - 1 : 0;
  }

  /**
   * Continues on the page the user reached by following a highlighted link.
   */
  function matchStepToPage(state, page) {
    const steps = getSteps();
    const current = steps[state.step];
    if (!current) {
      return false;
    }
    if (current.page === 'any' || current.page === page) {
      return true;
    }

    let index = steps.findIndex((step, i) => i > state.step && step.page === page);
    let direction = 1;
    if (index === -1) {
      for (let i = state.step - 1; i >= 0; i--) {
        if (steps[i].page === page) {
          index = i;
          direction = -1;
          break;
        }
      }
    }
    if (index === -1) {
      return false;
    }
    state.step = index;
    state.direction = direction;
    writeState(state);
    return true;
  }

  function goTo(index, direction) {
    const state = readState();
    if (!state) {
      return;
    }
    const page = currentPage();
    state.direction = direction;
    state.step = findReachableStepIndex(index, direction, state, page);
    writeState(state);

    const step = getSteps()[state.step];
    if (step.page !== 'any' && step.page !== page) {
      window.location.assign(pageUrl(step.page, state));
      return;
    }
    render();
  }

  async function completeOnServer() {
    const settings = config();
    if (!settings.completeUrl) {
      return;
    }
    try {
      const tokenResponse = await window.fetch(Drupal.url('session/token'), { credentials: 'same-origin' });
      if (!tokenResponse.ok) {
        return;
      }
      await window.fetch(settings.completeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': await tokenResponse.text(),
        },
        keepalive: true,
      });
    }
    catch (error) {
      // A failed save only means the tour may be offered again.
    }
  }

  function endTour() {
    unmountUi();
    clearState();
    return completeOnServer();
  }

  function finishTour() {
    const onDashboard = currentPage() === 'dashboard';
    const saved = Promise.race([
      endTour(),
      new Promise((resolve) => window.setTimeout(resolve, COMPLETE_TIMEOUT_MS)),
    ]);
    if (!onDashboard) {
      // Navigating away would cancel the save, so wait for it first.
      saved.then(() => window.location.assign(config().dashboardUrl));
    }
  }

  function startTour() {
    writeState({ step: 0, direction: 1, urls: {} });
    if (currentPage() === 'dashboard') {
      render();
    }
    else {
      window.location.assign(config().dashboardUrl);
    }
  }

  function runCommand(command) {
    const state = readState();
    if (!state) {
      return;
    }
    if (command === 'next') {
      goTo(state.step + 1, 1);
    }
    else if (command === 'back') {
      goTo(state.step - 1, -1);
    }
    else if (command === 'finish') {
      finishTour();
    }
    else {
      endTour();
    }
  }

  function render() {
    const state = readState();
    if (!state || !matchStepToPage(state, currentPage())) {
      unmountUi();
      return;
    }

    const step = getSteps()[state.step];
    const targets = step.targets ? step.targets() : [];
    if (!isStepUsable(step, targets)) {
      goTo(state.step + state.direction, state.direction);
      return;
    }

    if (step.capture) {
      step.capture(state, targets);
      writeState(state);
    }

    if (!ui) {
      mountUi();
    }
    ui.targets = targets;
    applyAccents(step.accents ? step.accents() : []);
    fillCard(step);
    observeTargets(targets);
    revealTargets(targets);
    layout();
    ui.card.focus({ preventScroll: true });
  }

  function mountUi() {
    const root = document.createElement('div');
    root.className = 'scs-onboarding';

    const backdrop = document.createElement('div');
    backdrop.className = 'scs-onboarding__backdrop';
    backdrop.setAttribute('aria-hidden', 'true');

    const card = document.createElement('div');
    card.className = 'scs-onboarding__card';
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.setAttribute('aria-labelledby', 'scs-onboarding-title');
    card.setAttribute('aria-describedby', 'scs-onboarding-content');
    card.tabIndex = -1;

    root.append(backdrop, card);
    document.body.appendChild(root);
    ui = { root, backdrop, card, targets: [], accents: [], observer: null, frame: 0 };

    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('resize', scheduleLayout);
    document.addEventListener('scroll', scheduleLayout, true);
  }

  function unmountUi() {
    if (!ui) {
      return;
    }
    document.removeEventListener('keydown', onKeydown, true);
    window.removeEventListener('resize', scheduleLayout);
    document.removeEventListener('scroll', scheduleLayout, true);
    if (ui.observer) {
      ui.observer.disconnect();
    }
    if (ui.frame) {
      window.cancelAnimationFrame(ui.frame);
    }
    clearAccents();
    ui.root.remove();
    ui = null;
  }

  function clearAccents() {
    ui.accents.forEach(({ element, tone }) => {
      element.classList.remove('scs-onboarding-outline--' + tone);
    });
    ui.accents = [];
  }

  function applyAccents(accents) {
    clearAccents();
    accents.forEach(({ element, tone }) => {
      element.classList.add('scs-onboarding-outline--' + tone);
    });
    ui.accents = accents;
  }

  function fillCard(step) {
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn btn--secondary btn--icon overlay__close scs-onboarding__close';
    closeButton.setAttribute('aria-label', Drupal.t('Close'));
    closeButton.innerHTML = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><line x1="14.8493" y1="14.8493" x2="4.94983" y2="4.94978" stroke="#17212B" stroke-width="2"/><line x1="4.94979" y1="14.8492" x2="14.8493" y2="4.94975" stroke="#17212B" stroke-width="2"/></svg>';
    closeButton.addEventListener('click', endTour);

    const content = document.createElement('div');
    content.className = 'scs-onboarding__content';
    content.id = 'scs-onboarding-content';
    step.blocks.forEach((block, index) => {
      const element = document.createElement(block.kind === 'heading' ? 'h2' : 'p');
      element.className = BLOCK_CLASSES[block.kind];
      if (index === 0) {
        element.id = 'scs-onboarding-title';
      }
      appendRichText(element, block.text, block.slots || []);
      content.appendChild(element);
    });

    const actions = document.createElement('div');
    actions.className = 'scs-onboarding__actions';
    step.actions.forEach((action) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn--' + action.tone;
      button.textContent = action.label;
      button.addEventListener('click', () => runCommand(action.command));
      actions.appendChild(button);
    });

    ui.card.replaceChildren(closeButton, content, actions);
  }

  function observeTargets(targets) {
    if (ui.observer) {
      ui.observer.disconnect();
    }
    if (typeof window.ResizeObserver !== 'function') {
      return;
    }
    // The body is observed too: late layout shifts move targets without resizing them.
    ui.observer = new window.ResizeObserver(scheduleLayout);
    targets.forEach((target) => ui.observer.observe(target));
    ui.observer.observe(document.body);
  }

  function revealTargets(targets) {
    if (!targets.length) {
      return;
    }
    const isLarge = targets[0].getBoundingClientRect().height > window.innerHeight * LARGE_TARGET_VIEWPORT_SHARE;
    targets[0].scrollIntoView({ block: isLarge ? 'start' : 'center', inline: 'nearest' });
  }

  function scheduleLayout() {
    if (!ui || ui.frame) {
      return;
    }
    ui.frame = window.requestAnimationFrame(() => {
      ui.frame = 0;
      layout();
    });
  }

  function paddedRect(element) {
    const rect = element.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) {
      return null;
    }
    return {
      left: rect.left - HOLE_PADDING,
      top: rect.top - HOLE_PADDING,
      right: rect.right + HOLE_PADDING,
      bottom: rect.bottom + HOLE_PADDING,
    };
  }

  function rectsOverlap(a, b) {
    return a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
  }

  function unionRect(a, b) {
    return {
      left: Math.min(a.left, b.left),
      top: Math.min(a.top, b.top),
      right: Math.max(a.right, b.right),
      bottom: Math.max(a.bottom, b.bottom),
    };
  }

  // Overlapping holes would cancel each other out under the even-odd fill rule.
  function mergeOverlappingRects(rects) {
    const merged = rects.slice();
    let didMerge = true;
    while (didMerge) {
      didMerge = false;
      for (let i = 0; i < merged.length && !didMerge; i++) {
        for (let j = i + 1; j < merged.length && !didMerge; j++) {
          if (rectsOverlap(merged[i], merged[j])) {
            merged[i] = unionRect(merged[i], merged[j]);
            merged.splice(j, 1);
            didMerge = true;
          }
        }
      }
    }
    return merged;
  }

  function roundedRectPath(rect) {
    const width = rect.right - rect.left;
    const height = rect.bottom - rect.top;
    const r = Math.min(HOLE_RADIUS, width / 2, height / 2);
    const { left: x, top: y, right, bottom } = rect;
    return 'M' + (x + r) + ' ' + y
      + 'H' + (right - r) + 'A' + r + ' ' + r + ' 0 0 1 ' + right + ' ' + (y + r)
      + 'V' + (bottom - r) + 'A' + r + ' ' + r + ' 0 0 1 ' + (right - r) + ' ' + bottom
      + 'H' + (x + r) + 'A' + r + ' ' + r + ' 0 0 1 ' + x + ' ' + (bottom - r)
      + 'V' + (y + r) + 'A' + r + ' ' + r + ' 0 0 1 ' + (x + r) + ' ' + y + 'Z';
  }

  function layout() {
    if (!ui) {
      return;
    }
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const holes = mergeOverlappingRects(ui.targets.map(paddedRect).filter(Boolean));

    ui.backdrop.style.clipPath = holes.length
      ? 'path(evenodd, "M0 0H' + viewportWidth + 'V' + viewportHeight + 'H0Z' + holes.map(roundedRectPath).join('') + '")'
      : 'none';
    placeCard(holes, viewportWidth, viewportHeight);
  }

  function placeCard(holes, viewportWidth, viewportHeight) {
    const card = ui.card;
    const isNarrow = viewportWidth < NARROW_VIEWPORT_WIDTH;
    card.style.width = isNarrow ? (viewportWidth - 2 * VIEWPORT_MARGIN) + 'px' : '';
    card.style.left = '0px';
    card.style.top = '0px';
    const { width, height } = card.getBoundingClientRect();
    const maxLeft = viewportWidth - VIEWPORT_MARGIN - width;
    const maxTop = viewportHeight - VIEWPORT_MARGIN - height;

    let left = (viewportWidth - width) / 2;
    let top = (viewportHeight - height) / 2;
    let placement = 'center';

    if (holes.length) {
      const focus = holes.reduce(unionRect);
      const visible = {
        left: Math.max(focus.left, 0),
        top: Math.max(focus.top, 0),
        right: Math.min(focus.right, viewportWidth),
        bottom: Math.min(focus.bottom, viewportHeight),
      };
      const centerX = (visible.left + visible.right) / 2;
      const centerY = (visible.top + visible.bottom) / 2;

      if (isNarrow) {
        left = VIEWPORT_MARGIN;
        top = centerY > viewportHeight / 2 ? VIEWPORT_MARGIN : maxTop;
        placement = 'sheet';
      }
      else {
        const sideTop = clamp(centerY - height / 2, VIEWPORT_MARGIN, maxTop);
        const alignedLeft = clamp(centerX - width / 2, VIEWPORT_MARGIN, maxLeft);
        const options = [
          ['right', visible.right + CARD_GAP + width <= viewportWidth - VIEWPORT_MARGIN, visible.right + CARD_GAP, sideTop],
          ['left', visible.left - CARD_GAP - width >= VIEWPORT_MARGIN, visible.left - CARD_GAP - width, sideTop],
          ['bottom', visible.bottom + CARD_GAP + height <= viewportHeight - VIEWPORT_MARGIN, alignedLeft, visible.bottom + CARD_GAP],
          ['top', visible.top - CARD_GAP - height >= VIEWPORT_MARGIN, alignedLeft, visible.top - CARD_GAP - height],
        ];
        const fitting = options.find((option) => option[1]);
        if (fitting) {
          [placement, , left, top] = fitting;
        }
        else {
          // The target leaves no room beside it, so the card floats at the bottom.
          placement = 'floating';
          top = clamp(viewportHeight - height - FLOATING_BOTTOM_OFFSET, VIEWPORT_MARGIN, maxTop);
        }
        card.style.setProperty('--scs-onboarding-caret-x', clamp(centerX - left, CARET_EDGE_OFFSET, width - CARET_EDGE_OFFSET) + 'px');
        card.style.setProperty('--scs-onboarding-caret-y', clamp(centerY - top, CARET_EDGE_OFFSET, height - CARET_EDGE_OFFSET) + 'px');
      }
    }

    card.dataset.placement = placement;
    card.style.left = left + 'px';
    card.style.top = top + 'px';
    card.style.visibility = 'visible';
  }

  function getFocusableElements() {
    const inCard = Array.from(ui.card.querySelectorAll(FOCUSABLE_SELECTOR));
    const inTargets = ui.targets.flatMap((target) => (
      (target.matches(FOCUSABLE_SELECTOR) ? [target] : []).concat(Array.from(target.querySelectorAll(FOCUSABLE_SELECTOR)))
    ));
    return inCard.concat(inTargets).filter((element) => element.getClientRects().length > 0);
  }

  function onKeydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      endTour();
      return;
    }
    if (event.key !== 'Tab') {
      return;
    }
    const focusable = getFocusableElements();
    if (!focusable.length) {
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (!focusable.includes(active)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    }
    else if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    }
    else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function onStartClick(event) {
    const trigger = event.target.closest('[data-scs-onboarding-start]');
    if (!trigger) {
      return;
    }
    event.preventDefault();
    const accountMenu = trigger.closest('.dashboard-sidebar__account-menu-content');
    if (accountMenu) {
      accountMenu.hidden = true;
      document.querySelector('.dashboard-sidebar__account-menu-header')?.setAttribute('aria-expanded', 'false');
    }
    startTour();
  }

  async function startOnFirstVisit() {
    const settings = config();
    if (!settings.stateUrl) {
      return;
    }
    try {
      const response = await window.fetch(settings.stateUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (response.ok && (await response.json()).autoStart) {
        startTour();
      }
    }
    catch (error) {
      // Without the state the tour just does not start by itself.
    }
  }

  Drupal.behaviors.sodaScsOnboardingTour = {
    attach(context) {
      once('scsOnboardingTour', 'html', context).forEach(() => {
        document.addEventListener('click', onStartClick);
        if (readState()) {
          render();
        }
        else {
          startOnFirstVisit();
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
