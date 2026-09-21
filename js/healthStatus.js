/**
 * @file
 * Canonical health statuses: running, stopped, problem, unknown.
 */
(function (Drupal) {
  const ALL = ['running', 'stopped', 'problem', 'unknown'];

  const ALIASES = {
    healthy: 'running',
    available: 'running',
    online: 'running',
    success: 'running',
    ok: 'running',
    paused: 'stopped',
    exited: 'stopped',
    created: 'stopped',
    removing: 'stopped',
    unhealthy: 'problem',
    unavailable: 'problem',
    error: 'problem',
    failure: 'problem',
    dead: 'problem',
    offline: 'problem',
    tls: 'problem',
    starting: 'unknown',
    checking: 'unknown',
    not_found: 'unknown',
    pending: 'unknown',
  };

  function fromStatus(status) {
    const normalized = String(status || '').toLowerCase().trim();
    if (ALL.indexOf(normalized) !== -1) {
      return normalized;
    }
    return ALIASES[normalized] || 'unknown';
  }

  function fromPayload(data) {
    if (!data || !data.status || typeof data.status !== 'object') {
      return 'unknown';
    }
    return fromStatus(data.status.status);
  }

  function label(status) {
    switch (fromStatus(status)) {
      case 'running':
        return Drupal.t('Running');
      case 'stopped':
        return Drupal.t('Stopped');
      case 'problem':
        return Drupal.t('Problem');
      default:
        return Drupal.t('Unknown');
    }
  }

  function detail(data) {
    if (!data || !data.status || typeof data.status !== 'object') {
      return '';
    }
    return String(data.status.error || data.status.message || '');
  }

  Drupal.sodaScsHealth = {
    ALL: ALL,
    PROBLEM: 'problem',
    RUNNING: 'running',
    STOPPED: 'stopped',
    UNKNOWN: 'unknown',
    detail: detail,
    fromPayload: fromPayload,
    fromStatus: fromStatus,
    label: label,
  };
})(Drupal);
