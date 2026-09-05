/* =========================================================================
   orbit.js — mouse/touch drag orbit + scroll zoom for the CSS-3D scene.
   Exposes a small global `Orbit` API used by scene.js and the toolbar.
   ========================================================================= */
(function () {
  "use strict";

  var viewport = document.getElementById("viewport");
  var zoomEl   = document.getElementById("zoom");
  var worldEl  = document.getElementById("world");

  // Default camera pose.
  var DEFAULT = { rx: -18, ry: -26, zoom: 1 };

  var state   = { rx: DEFAULT.rx, ry: DEFAULT.ry, zoom: DEFAULT.zoom };
  var target  = { rx: DEFAULT.rx, ry: DEFAULT.ry, zoom: DEFAULT.zoom };

  var dragging = false;
  var lastX = 0, lastY = 0;
  var autoRotate = true;
  var idleTimer = null;
  var IDLE_MS = 2500;

  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  function apply() {
    worldEl.style.setProperty("--rx", state.rx.toFixed(2) + "deg");
    worldEl.style.setProperty("--ry", state.ry.toFixed(2) + "deg");
    zoomEl.style.setProperty("--zoom", state.zoom.toFixed(3));
  }

  // Smooth easing loop.
  function tick() {
    if (autoRotate && !dragging) {
      target.ry += 0.12; // gentle auto-rotate
    }
    state.rx += (target.rx - state.rx) * 0.12;
    state.ry += (target.ry - state.ry) * 0.12;
    state.zoom += (target.zoom - state.zoom) * 0.15;
    apply();
    requestAnimationFrame(tick);
  }

  function markActivity() {
    if (idleTimer) clearTimeout(idleTimer);
    // pause auto-rotate briefly after user interaction, then resume if enabled
    if (Orbit._autoWanted) {
      autoRotate = false;
      idleTimer = setTimeout(function () { autoRotate = true; }, IDLE_MS);
    }
  }

  /* ---- Pointer (mouse + touch via Pointer Events) ---------------------- */
  function onDown(e) {
    dragging = true;
    viewport.classList.add("dragging");
    var p = pointFromEvent(e);
    lastX = p.x; lastY = p.y;
    markActivity();
    if (e.pointerId !== undefined && viewport.setPointerCapture) {
      try { viewport.setPointerCapture(e.pointerId); } catch (_) {}
    }
  }
  function onMove(e) {
    if (!dragging) return;
    var p = pointFromEvent(e);
    var dx = p.x - lastX;
    var dy = p.y - lastY;
    lastX = p.x; lastY = p.y;
    target.ry += dx * 0.4;
    target.rx = clamp(target.rx - dy * 0.4, -85, 85);
    markActivity();
  }
  function onUp(e) {
    dragging = false;
    viewport.classList.remove("dragging");
    markActivity();
  }

  function pointFromEvent(e) {
    if (e.touches && e.touches.length) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    return { x: e.clientX, y: e.clientY };
  }

  function onWheel(e) {
    e.preventDefault();
    var delta = e.deltaY > 0 ? -0.08 : 0.08;
    target.zoom = clamp(target.zoom + delta, 0.45, 2.2);
    markActivity();
  }

  // Prefer Pointer Events; fall back to mouse/touch.
  if (window.PointerEvent) {
    viewport.addEventListener("pointerdown", onDown);
    window.addEventListener("pointermove", onMove);
    window.addEventListener("pointerup", onUp);
  } else {
    viewport.addEventListener("mousedown", onDown);
    window.addEventListener("mousemove", onMove);
    window.addEventListener("mouseup", onUp);
    viewport.addEventListener("touchstart", onDown, { passive: true });
    window.addEventListener("touchmove", onMove, { passive: true });
    window.addEventListener("touchend", onUp);
  }
  viewport.addEventListener("wheel", onWheel, { passive: false });

  /* ---- Public API ------------------------------------------------------ */
  var Orbit = {
    _autoWanted: true,
    reset: function () {
      target.rx = DEFAULT.rx;
      target.ry = DEFAULT.ry;
      target.zoom = DEFAULT.zoom;
      markActivity();
    },
    setAutoRotate: function (on) {
      this._autoWanted = !!on;
      autoRotate = !!on;
    },
    // Move camera to a named focus (used by the guided tour).
    focus: function (pose) {
      if (!pose) return;
      if (typeof pose.rx === "number") target.rx = pose.rx;
      if (typeof pose.ry === "number") target.ry = pose.ry;
      if (typeof pose.zoom === "number") target.zoom = clamp(pose.zoom, 0.45, 2.2);
    }
  };
  window.Orbit = Orbit;

  apply();
  requestAnimationFrame(tick);
})();
