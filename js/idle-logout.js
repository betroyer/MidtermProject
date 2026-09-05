/**
 * js/idle-logout.js — auto sign-out after 5 minutes with no mouse/keyboard/form activity.
 * Also pings the server so PHP session last_activity stays in sync.
 */
(function () {
  var IDLE_MS = 5 * 60 * 1000;
  var WARN_MS = 4 * 60 * 1000;
  var PING_MIN_MS = 30 * 1000;
  var cfg = window.SIMS_IDLE || {};
  var logoutUrl = cfg.logoutUrl || '../logout.php?reason=idle';
  var pingUrl = cfg.pingUrl || '../api/session_ping.php';
  var idleMs = typeof cfg.idleMs === 'number' ? cfg.idleMs : IDLE_MS;
  var warnMs = typeof cfg.warnMs === 'number' ? cfg.warnMs : WARN_MS;

  var idleTimer = null;
  var warnTimer = null;
  var lastPing = 0;
  var loggingOut = false;
  var banner = null;

  function hideWarn() {
    if (banner && banner.parentNode) {
      banner.parentNode.removeChild(banner);
    }
    banner = null;
  }

  function showWarn() {
    if (banner) return;
    banner = document.createElement('div');
    banner.className = 'idle-warn';
    banner.setAttribute('role', 'alert');
    banner.setAttribute('data-testid', 'idle-warn');
    banner.innerHTML =
      '<strong>Still there?</strong> You will be signed out in about 1 minute due to inactivity. ' +
      '<button type="button" class="btn" data-idle-stay>Stay signed in</button>';
    document.body.appendChild(banner);
    var btn = banner.querySelector('[data-idle-stay]');
    if (btn) {
      btn.addEventListener('click', function () {
        resetIdle('click');
      });
    }
  }

  function doLogout() {
    if (loggingOut) return;
    loggingOut = true;
    hideWarn();
    window.location.href = logoutUrl;
  }

  function pingServer() {
    var now = Date.now();
    if (now - lastPing < PING_MIN_MS) return;
    lastPing = now;
    try {
      fetch(pingUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          if (res.status === 401) {
            doLogout();
          }
        })
        .catch(function () {});
    } catch (e) {}
  }

  function resetIdle() {
    if (loggingOut) return;
    hideWarn();
    if (idleTimer) clearTimeout(idleTimer);
    if (warnTimer) clearTimeout(warnTimer);
    warnTimer = setTimeout(showWarn, Math.max(0, warnMs));
    idleTimer = setTimeout(doLogout, idleMs);
    pingServer();
  }

  var events = [
    'mousemove',
    'mousedown',
    'mouseup',
    'click',
    'keydown',
    'keypress',
    'keyup',
    'scroll',
    'wheel',
    'touchstart',
    'touchmove',
    'pointermove',
    'pointerdown',
    'input',
    'change',
    'submit',
    'focus',
  ];

  var throttleUntil = 0;
  function onActivity() {
    var now = Date.now();
    if (now < throttleUntil) return;
    throttleUntil = now + 1000; // don't thrash on constant mousemove
    resetIdle();
  }

  events.forEach(function (ev) {
    document.addEventListener(ev, onActivity, { capture: true, passive: true });
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      resetIdle();
    }
  });

  resetIdle();
})();
