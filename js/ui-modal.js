/**
 * Shared modal controller for admin list pages.
 * Usage: UIModal.open({ title, subtitle?, html?, url?, wide?, onOpen? })
 */
(function (global) {
  'use strict';

  var root = null;
  var panel = null;
  var titleEl = null;
  var subEl = null;
  var bodyEl = null;
  var footEl = null;
  var lastFocus = null;
  var onCloseHook = null;

  function ensureDom() {
    if (root) return;
    root = document.createElement('div');
    root.className = 'ui-modal-root';
    root.id = 'ui-modal-root';
    root.hidden = true;
    root.setAttribute('data-testid', 'ui-modal');
    root.innerHTML =
      '<button type="button" class="ui-modal-backdrop" aria-label="Close dialog" data-ui-modal-dismiss></button>' +
      '<div class="ui-modal-panel" role="dialog" aria-modal="true" aria-labelledby="ui-modal-title" tabindex="-1">' +
      '  <div class="ui-modal-head">' +
      '    <div>' +
      '      <h2 class="ui-modal-title" id="ui-modal-title"></h2>' +
      '      <p class="ui-modal-sub" id="ui-modal-sub" hidden></p>' +
      '    </div>' +
      '    <button type="button" class="ui-modal-close" aria-label="Close" data-ui-modal-dismiss data-testid="ui-modal-close">' +
      '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
      '    </button>' +
      '  </div>' +
      '  <div class="ui-modal-body" id="ui-modal-body"></div>' +
      '  <div class="ui-modal-foot" id="ui-modal-foot" hidden></div>' +
      '</div>';
    document.body.appendChild(root);
    panel = root.querySelector('.ui-modal-panel');
    titleEl = root.querySelector('#ui-modal-title');
    subEl = root.querySelector('#ui-modal-sub');
    bodyEl = root.querySelector('#ui-modal-body');
    footEl = root.querySelector('#ui-modal-foot');

    root.addEventListener('click', function (e) {
      if (e.target.closest('[data-ui-modal-dismiss]')) {
        close();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (root.hidden) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
      }
      if (e.key === 'Tab') trapFocus(e);
    });
  }

  function focusables() {
    return panel.querySelectorAll(
      'a[href],button:not([disabled]),textarea,input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])'
    );
  }

  function trapFocus(e) {
    var nodes = Array.prototype.slice.call(focusables()).filter(function (n) {
      return n.offsetParent !== null || n === panel;
    });
    if (!nodes.length) {
      e.preventDefault();
      panel.focus();
      return;
    }
    var first = nodes[0];
    var last = nodes[nodes.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function setLoading() {
    bodyEl.innerHTML =
      '<div class="ui-modal-loading" role="status" aria-live="polite">' +
      '<div class="ui-modal-spinner" aria-hidden="true"></div>' +
      '<span>Loading…</span></div>';
    footEl.hidden = true;
    footEl.innerHTML = '';
  }

  function open(opts) {
    opts = opts || {};
    ensureDom();
    lastFocus = document.activeElement;
    onCloseHook = typeof opts.onClose === 'function' ? opts.onClose : null;
    titleEl.textContent = opts.title || 'Details';
    if (opts.subtitle) {
      subEl.hidden = false;
      subEl.textContent = opts.subtitle;
    } else {
      subEl.hidden = true;
      subEl.textContent = '';
    }
    panel.classList.toggle('ui-modal-panel--wide', !!opts.wide);
    panel.classList.toggle('ui-modal-panel--roster', !!opts.roster);
    root.hidden = false;
    document.body.classList.add('ui-modal-open');

    if (opts.html != null) {
      bodyEl.innerHTML = opts.html;
      var form = bodyEl.querySelector('form');
      if (form && !form.id) {
        form.id = 'ui-modal-form';
      }
      var inlineFoot = bodyEl.querySelector('[data-modal-footer]');
      if (inlineFoot) {
        footEl.hidden = false;
        footEl.innerHTML = inlineFoot.innerHTML;
        inlineFoot.remove();
        if (form && form.id) {
          footEl.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
            btn.setAttribute('form', form.id);
          });
        }
      } else if (opts.footerHtml) {
        footEl.hidden = false;
        footEl.innerHTML = opts.footerHtml;
        if (form && form.id) {
          footEl.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
            btn.setAttribute('form', form.id);
          });
        }
      } else {
        footEl.hidden = true;
        footEl.innerHTML = '';
      }
      focusFirst();
      if (typeof opts.onOpen === 'function') opts.onOpen(bodyEl, footEl);
      return Promise.resolve();
    }

    if (opts.url) {
      setLoading();
      return fetch(opts.url, {
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then(function (res) {
          if (!res.ok) throw new Error('Could not load details (' + res.status + ').');
          return res.text();
        })
        .then(function (html) {
          bodyEl.innerHTML = html;
          var form = bodyEl.querySelector('form');
          if (form && !form.id) {
            form.id = 'ui-modal-form';
          }
          var foot = bodyEl.querySelector('[data-modal-footer]');
          if (foot) {
            footEl.hidden = false;
            footEl.innerHTML = foot.innerHTML;
            foot.remove();
            if (form && form.id) {
              footEl.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                btn.setAttribute('form', form.id);
              });
            }
          } else if (opts.footerHtml) {
            footEl.hidden = false;
            footEl.innerHTML = opts.footerHtml;
          } else {
            footEl.hidden = true;
            footEl.innerHTML = '';
          }
          focusFirst();
          if (typeof opts.onOpen === 'function') opts.onOpen(bodyEl, footEl);
        })
        .catch(function (err) {
          bodyEl.innerHTML =
            '<div class="ui-modal-error" role="alert">' +
            (err && err.message ? err.message : 'Something went wrong.') +
            ' <button type="button" class="btn-out" data-ui-modal-dismiss style="margin-left:8px">Close</button></div>';
          footEl.hidden = true;
        });
    }

    focusFirst();
    return Promise.resolve();
  }

  function focusFirst() {
    var nodes = focusables();
    var preferred = bodyEl.querySelector('[data-autofocus]') || nodes[0] || panel;
    setTimeout(function () {
      preferred.focus();
    }, 10);
  }

  function close() {
    if (!root || root.hidden) return;
    root.hidden = true;
    document.body.classList.remove('ui-modal-open');
    bodyEl.innerHTML = '';
    footEl.innerHTML = '';
    footEl.hidden = true;
    if (onCloseHook) {
      try {
        onCloseHook();
      } catch (e) {}
      onCloseHook = null;
    }
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
  }

  global.UIModal = { open: open, close: close };
})(window);
