<?php
/**
 * walkthrough.php — CSS-3D security walkthrough. Theme follows the same
 * Dark / Light choice used on login and every role dashboard.
 */
require_once __DIR__ . '/includes/auth.php';
$theme = current_theme();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Secure SIMS · 3D Security Walkthrough</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/scene.css" />
</head>
<body data-testid="app-body">

  <!-- Top bar -------------------------------------------------------------->
  <header class="topbar" data-testid="topbar">
    <div class="brand">
      <span class="brand-dot"></span>
      <div>
        <h1 class="brand-title">Secure&nbsp;SIMS</h1>
        <p class="brand-sub">Student Information &amp; Access Management · 3D Walkthrough</p>
      </div>
    </div>

    <div class="toolbar" data-testid="toolbar">
      <a class="btn" href="index.php" data-testid="walkthrough-home"><span class="ic">←</span> Login</a>
      <span class="datasrc" data-testid="data-source" id="dataSource" title="Where the scene data came from">source: …</span>
      <button class="btn" id="tourBtn" data-testid="guided-tour-btn"><span class="ic">▶</span> Guided Tour</button>
      <button class="btn" id="resetBtn" data-testid="reset-view-btn"><span class="ic">⟳</span> Reset View</button>
      <?= theme_switch_form('theme.php') ?>
      <button class="btn btn-icon" id="settingsBtn" data-testid="settings-btn" aria-label="Settings">⚙</button>
    </div>
  </header>

  <!-- On-screen hint ------------------------------------------------------->
  <div class="hint" id="hint" data-testid="hint-bar">drag to rotate • scroll to zoom • click glowing parts</div>

  <!-- 3D stage ------------------------------------------------------------->
  <main class="viewport" id="viewport" data-testid="viewport">
    <div class="zoom" id="zoom">
      <div class="world" id="world" data-testid="world">

        <!-- floor grid -->
        <div class="floor" aria-hidden="true">
          <div class="grid"></div>
        </div>

        <!-- Architecture row: Browser -> PHP -> MySQL -->
        <div class="node node--browser" data-hotspot="browser" data-testid="node-browser">
          <div class="cube">
            <div class="face face-front"></div>
            <div class="face face-back"></div>
            <div class="face face-right"></div>
            <div class="face face-left"></div>
            <div class="face face-top"><span class="glyph">◻</span></div>
            <div class="face face-bottom"></div>
          </div>
          <div class="node-label">Browser</div>
        </div>

        <div class="node node--php" data-hotspot="php" data-testid="node-php">
          <div class="cube">
            <div class="face face-front"></div>
            <div class="face face-back"></div>
            <div class="face face-right"></div>
            <div class="face face-left"></div>
            <div class="face face-top"><span class="glyph">&lt;?php</span></div>
            <div class="face face-bottom"></div>
          </div>
          <div class="node-label">PHP&nbsp;App</div>
        </div>

        <div class="node node--mysql" data-hotspot="mysql" data-testid="node-mysql">
          <div class="cube cube--db">
            <div class="face face-front"></div>
            <div class="face face-back"></div>
            <div class="face face-right"></div>
            <div class="face face-left"></div>
            <div class="face face-top"><span class="glyph">▤</span></div>
            <div class="face face-bottom"></div>
          </div>
          <div class="node-label">MySQL&nbsp;DB</div>
        </div>

        <!-- connecting beams -->
        <div class="beam beam--a" aria-hidden="true"></div>
        <div class="beam beam--b" aria-hidden="true"></div>

        <!-- animated packet lane -->
        <div class="packets" id="packets" data-testid="packets" aria-hidden="true"></div>

        <!-- transient security effect anchor (near PHP node) -->
        <div class="fx" id="fx" aria-hidden="true"></div>

        <!-- RBAC stage: avatar + doors -->
        <div class="rbac" data-testid="rbac-stage">
          <div class="avatar" id="avatar" data-hotspot="rbac" data-testid="rbac-avatar">
            <div class="avatar-head"></div>
            <div class="avatar-body"></div>
            <div class="badge" id="roleBadge" data-testid="role-badge">?</div>
          </div>
          <div class="doors" id="doors" data-testid="doors"></div>
        </div>

      </div>
    </div>
  </main>

  <!-- Role switcher -------------------------------------------------------->
  <section class="roles-dock" id="rolesDock" data-testid="roles-dock">
    <span class="dock-title">Impersonate role</span>
    <div class="role-btns" id="roleBtns"></div>
  </section>

  <!-- Security concept dock ----------------------------------------------->
  <section class="sec-dock" id="secDock" data-testid="sec-dock">
    <span class="dock-title">Security concepts</span>
    <div class="sec-btns" id="secBtns"></div>
  </section>

  <!-- Caption panel -------------------------------------------------------->
  <aside class="caption" id="caption" data-testid="caption-panel" hidden>
    <button class="caption-close" id="captionClose" data-testid="caption-close" aria-label="Close">✕</button>
    <h3 class="caption-title" id="captionTitle"></h3>
    <p class="caption-body" id="captionBody"></p>
    <div class="caption-extra" id="captionExtra"></div>
  </aside>

  <!-- Settings panel ------------------------------------------------------->
  <div class="drawer" id="settingsDrawer" data-testid="settings-drawer" hidden>
    <div class="drawer-head">
      <h3>Settings</h3>
      <button class="btn btn-icon" id="settingsClose" data-testid="settings-close" aria-label="Close">✕</button>
    </div>
    <label class="row">
      <span>Theme</span>
      <select id="themeSelect" data-testid="theme-select">
        <option value="dark">Dark (control room)</option>
        <option value="light">Light</option>
      </select>
    </label>
    <label class="row">
      <span>Auto-rotate scene</span>
      <input type="checkbox" id="autoRotate" data-testid="autorotate-toggle" checked />
    </label>
    <label class="row">
      <span>Animate packets</span>
      <input type="checkbox" id="packetToggle" data-testid="packet-toggle" checked />
    </label>
    <label class="row">
      <span>Reduce motion</span>
      <input type="checkbox" id="reduceMotion" data-testid="reduce-motion-toggle" />
    </label>
    <p class="drawer-note">Preferences are saved on this device.</p>
  </div>

  <!-- Guided tour caption strip ------------------------------------------->
  <div class="tour" id="tour" data-testid="tour-strip" hidden>
    <div class="tour-inner">
      <span class="tour-step" id="tourStep"></span>
      <p class="tour-text" id="tourText"></p>
      <div class="tour-controls">
        <button class="btn" id="tourPrev" data-testid="tour-prev">Back</button>
        <button class="btn" id="tourNext" data-testid="tour-next">Next</button>
        <button class="btn btn-icon" id="tourExit" data-testid="tour-exit" aria-label="Exit tour">✕</button>
      </div>
    </div>
  </div>

  <!-- Toast (alerts, e.g. tamper detected) -------------------------------->
  <div class="toast" id="toast" data-testid="toast" hidden></div>

  <script src="js/orbit.js"></script>
  <script src="js/scene.js"></script>
</body>
</html>
