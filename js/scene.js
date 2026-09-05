/* =========================================================================
   scene.js — data fetch, RBAC logic, packet flow, hotspots, guided tour,
   settings. Vanilla JS, no dependencies.
   ========================================================================= */
(function () {
  "use strict";

  /* ---- Built-in fallback (mirrors api/data.php) ------------------------ */
  var FALLBACK = {
    source: "fallback (embedded)",
    hint: "drag to rotate • scroll to zoom • click glowing parts",
    roles: [
      { id: 1, code: "admin",   name: "Administrator",   color: "#ff4d5e", description: "Full control of the system: manages users, roles, and all academic records." },
      { id: 2, code: "teacher", name: "Teacher / Staff", color: "#4d9bff", description: "Manages grades and attendance for their classes and views reports." },
      { id: 3, code: "student", name: "Student",         color: "#43d17a", description: "Views their own profile and grades. No access to other records." }
    ],
    permissions: [
      { code: "dashboard",  label: "Dashboard" }, { code: "profile", label: "My Profile" },
      { code: "students",   label: "Students" },  { code: "grades",  label: "Grades" },
      { code: "attendance", label: "Attendance" },{ code: "reports", label: "Reports" },
      { code: "users",      label: "User Admin" },{ code: "roles",   label: "Role Admin" },
      { code: "audit_log",  label: "Audit Log" }
    ],
    matrix: {
      admin:   ["dashboard","profile","students","grades","attendance","reports","users","roles","audit_log"],
      teacher: ["dashboard","profile","students","grades","attendance","reports"],
      student: ["dashboard","profile","grades"]
    },
    students: [
      { id: 1, name: "Aisha Rahman",   email: "aisha.r@school.edu",  grade_level: "Grade 11", gpa: 3.92 },
      { id: 2, name: "Diego Martinez", email: "diego.m@school.edu",  grade_level: "Grade 10", gpa: 3.45 },
      { id: 3, name: "Mei Lin",        email: "mei.l@school.edu",    grade_level: "Grade 12", gpa: 3.78 },
      { id: 4, name: "Samuel Osei",    email: "samuel.o@school.edu", grade_level: "Grade 11", gpa: 3.10 },
      { id: 5, name: "Priya Nair",     email: "priya.n@school.edu",  grade_level: "Grade 9",  gpa: 4.00 }
    ],
    audit: [
      { actor_role: "admin",   action: "LOGIN_SUCCESS",      target: "session",        created_at: "2025-06-01 08:02:11" },
      { actor_role: "teacher", action: "GRADE_UPDATED",      target: "student#2",      created_at: "2025-06-01 09:14:37" },
      { actor_role: "student", action: "ACCESS_DENIED",      target: "module:users",   created_at: "2025-06-01 09:20:05" },
      { actor_role: "admin",   action: "ROLE_ASSIGNED",      target: "user#7:teacher", created_at: "2025-06-01 10:01:52" },
      { actor_role: "teacher", action: "ATTENDANCE_MARKED",  target: "class:11-B",     created_at: "2025-06-01 10:45:19" },
      { actor_role: "admin",   action: "INTEGRITY_CHECK_OK", target: "audit_log",      created_at: "2025-06-01 11:30:00" }
    ],
    architecture: [
      { code: "browser", title: "Browser (Client)", caption: "The client device. Sends HTTPS requests and renders responses. All user input is treated as untrusted until validated on the server." },
      { code: "php",     title: "PHP Application",   caption: "The application server. Authenticates users, enforces Role-Based Access Control, validates input, and talks to MySQL using PDO prepared statements." },
      { code: "mysql",   title: "MySQL Database",    caption: "Persistent storage for users, roles, permissions, students and the audit log. Reached only through least-privilege accounts and parameterised queries." }
    ],
    security: [
      { code: "confidentiality", title: "Confidentiality", caption: "Only authorised parties can read the data. Packets travel inside an encrypted shield; unauthorised \u201Cpeek\u201D attempts simply bounce off." },
      { code: "integrity",       title: "Integrity",       caption: "Data cannot be altered undetected. Each packet carries a checksum seal \u2014 tampering cracks the seal and instantly raises an alert." },
      { code: "availability",    title: "Availability",    caption: "Authorised users get reliable, timely access. The system stays lit and responsive as requests keep flowing without interruption." },
      { code: "rbac",            title: "Role-Based Access Control", caption: "Every user is assigned a role, and roles grant permissions. A user can only open the module \u201Cdoors\u201D their role permits \u2014 everything else stays locked." }
    ]
  };

  var DATA = FALLBACK;
  var currentRole = null;

  /* ---- Element refs ---------------------------------------------------- */
  var $ = function (id) { return document.getElementById(id); };
  var els = {
    dataSource: $("dataSource"), hint: $("hint"),
    roleBtns: $("roleBtns"), secBtns: $("secBtns"),
    doors: $("doors"), roleBadge: $("roleBadge"), avatarBody: null,
    packets: $("packets"), fx: $("fx"),
    caption: $("caption"), captionTitle: $("captionTitle"),
    captionBody: $("captionBody"), captionExtra: $("captionExtra"),
    captionClose: $("captionClose"),
    toast: $("toast")
  };

  /* ---- Boot ------------------------------------------------------------ */
  fetchData().then(function (data) {
    if (data) DATA = data;
    els.dataSource.textContent = "source: " + (DATA.source || "unknown");
    els.hint.textContent = DATA.hint || els.hint.textContent;
    buildPackets();
    buildRoles();
    buildDoors();
    buildSecurity();
    selectRole("admin"); // start with a full-access demo
    initSettings();
    initToolbar();
    initHotspots();
    initTour();
  });

  function fetchData() {
    return fetch("api/data.php", { headers: { "Accept": "application/json" } })
      .then(function (r) { if (!r.ok) throw new Error("bad status"); return r.json(); })
      .catch(function () { return null; }); // -> use embedded FALLBACK
  }

  /* ---- Packet pool ----------------------------------------------------- */
  function buildPackets() {
    els.packets.innerHTML = "";
    var forwards = 3, backs = 2;
    for (var i = 0; i < forwards; i++) addPacket("packet-forward", (i * 3.4) / forwards);
    for (var j = 0; j < backs; j++) addPacket("packet-back", 1.2 + (j * 3.4) / backs);
  }
  function addPacket(cls, delay) {
    var p = document.createElement("div");
    p.className = "packet " + cls;
    p.style.animationDelay = "-" + delay.toFixed(2) + "s";
    els.packets.appendChild(p);
  }

  /* ---- Roles ----------------------------------------------------------- */
  function buildRoles() {
    els.roleBtns.innerHTML = "";
    DATA.roles.forEach(function (role) {
      var b = document.createElement("button");
      b.className = "chip";
      b.dataset.role = role.code;
      b.setAttribute("data-testid", "role-chip-" + role.code);
      b.innerHTML = '<span class="swatch" style="background:' + role.color + '"></span>' + role.name;
      b.addEventListener("click", function () { selectRole(role.code); });
      els.roleBtns.appendChild(b);
    });
  }

  function roleByCode(code) {
    return DATA.roles.filter(function (r) { return r.code === code; })[0];
  }

  function selectRole(code) {
    currentRole = code;
    var role = roleByCode(code);
    if (!role) return;

    // chips
    Array.prototype.forEach.call(els.roleBtns.children, function (b) {
      b.classList.toggle("active", b.dataset.role === code);
    });

    // badge
    els.roleBadge.textContent = role.name;
    els.roleBadge.style.background = role.color;
    els.roleBadge.style.boxShadow = "0 0 14px " + role.color;

    // avatar body tint
    var body = document.querySelector(".avatar-body");
    if (body) { body.style.borderColor = role.color; body.style.boxShadow = "0 0 18px " + role.color; }

    // doors
    var allowed = DATA.matrix[code] || [];
    Array.prototype.forEach.call(els.doors.children, function (d) {
      var ok = allowed.indexOf(d.dataset.perm) !== -1;
      d.classList.toggle("unlocked", ok);
      d.classList.toggle("locked", !ok);
      d.querySelector(".lock").textContent = ok ? "\u{1F513}" : "\u{1F512}";
    });
  }

  /* ---- Doors ----------------------------------------------------------- */
  function buildDoors() {
    els.doors.innerHTML = "";
    DATA.permissions.forEach(function (perm) {
      var d = document.createElement("div");
      d.className = "door locked";
      d.dataset.perm = perm.code;
      d.setAttribute("data-testid", "door-" + perm.code);
      d.innerHTML = '<span class="lock">\u{1F512}</span><span class="door-name">' + perm.label + "</span>";
      els.doors.appendChild(d);
    });
  }

  /* ---- Security concepts ---------------------------------------------- */
  function buildSecurity() {
    els.secBtns.innerHTML = "";
    DATA.security.forEach(function (s) {
      var b = document.createElement("button");
      b.className = "chip sec";
      b.dataset.sec = s.code;
      b.setAttribute("data-testid", "sec-chip-" + s.code);
      b.textContent = s.title;
      b.addEventListener("click", function () { runSecurity(s.code, b); });
      els.secBtns.appendChild(b);
    });
  }

  var packetMode = "";
  function setPacketMode(mode) {
    els.packets.classList.remove("mode-confidentiality", "mode-integrity", "mode-availability", "tampered");
    if (mode) els.packets.classList.add("mode-" + mode);
    packetMode = mode;
  }

  function runSecurity(code, btn) {
    // toggle active chip highlight
    Array.prototype.forEach.call(els.secBtns.children, function (b) {
      b.classList.toggle("active", b === btn);
    });

    var concept = DATA.security.filter(function (s) { return s.code === code; })[0];
    if (concept) showCaption(concept.title, concept.caption);

    if (code === "rbac") { setPacketMode(""); Orbit.focus({ rx: -30, ry: 12, zoom: 1.05 }); return; }

    setPacketMode(code);
    Orbit.focus({ rx: -14, ry: -20, zoom: 1.1 });

    if (code === "confidentiality") spawnPeek();
    else if (code === "integrity") triggerTamper();
    else if (code === "availability") spawnWave(false);
  }

  function spawnPeek() {
    var eye = document.createElement("div");
    eye.className = "peek";
    eye.textContent = "\u{1F441}";
    els.fx.appendChild(eye);
    setTimeout(function () { eye.remove(); }, 1200);
  }

  function spawnWave(danger) {
    var w = document.createElement("div");
    w.className = "wave" + (danger ? " danger" : "");
    els.fx.appendChild(w);
    setTimeout(function () { w.remove(); }, 1000);
  }

  function triggerTamper() {
    els.packets.classList.add("tampered");
    spawnWave(true);
    showToast("\u26A0 Integrity alert: checksum mismatch \u2014 tampering detected & blocked");
    setTimeout(function () {
      els.packets.classList.remove("tampered");
    }, 2600);
  }

  /* ---- Captions / hotspots -------------------------------------------- */
  function showCaption(title, body, extraHtml) {
    els.captionTitle.textContent = title;
    els.captionBody.textContent = body;
    els.captionExtra.innerHTML = extraHtml || "";
    els.caption.hidden = false;
  }

  function initHotspots() {
    document.querySelectorAll("[data-hotspot]").forEach(function (node) {
      node.addEventListener("click", function () {
        var key = node.dataset.hotspot;
        if (key === "rbac") return showRbacCaption();
        var arch = DATA.architecture.filter(function (a) { return a.code === key; })[0];
        if (!arch) return;
        var extra = "";
        if (key === "mysql") extra = studentsTable();
        if (key === "php") extra = auditPreview();
        showCaption(arch.title, arch.caption, extra);
      });
    });
    els.captionClose.addEventListener("click", function () { els.caption.hidden = true; });
  }

  function showRbacCaption() {
    var concept = DATA.security.filter(function (s) { return s.code === "rbac"; })[0];
    var role = roleByCode(currentRole);
    var allowed = DATA.matrix[currentRole] || [];
    var rows = DATA.permissions.map(function (p) {
      var ok = allowed.indexOf(p.code) !== -1;
      return '<tr><td class="mono">' + p.label + "</td><td style=\"text-align:right\">" +
        (ok ? '\u2705' : '\u{1F512}') + "</td></tr>";
    }).join("");
    var extra = '<p style="font-size:12px;margin:0 0 8px;color:var(--ink-dim)">Current role: <b style="color:' +
      (role ? role.color : "#fff") + '">' + (role ? role.name : "-") + "</b></p>" +
      '<table>' + rows + "</table>";
    showCaption(concept.title, concept.caption, extra);
  }

  function studentsTable() {
    var rows = DATA.students.slice(0, 5).map(function (s) {
      return "<tr><td class=\"mono\">" + s.name + "</td><td>" + s.grade_level +
        "</td><td style=\"text-align:right\">" + Number(s.gpa).toFixed(2) + "</td></tr>";
    }).join("");
    return '<p style="font-size:12px;margin:0 0 6px;color:var(--ink-dim)">Sample rows (via PDO prepared statements):</p><table>' + rows + "</table>";
  }

  function auditPreview() {
    var rows = DATA.audit.slice(0, 4).map(function (a) {
      return "<tr><td class=\"mono\">" + a.action + "</td><td>" + a.actor_role + "</td></tr>";
    }).join("");
    return '<p style="font-size:12px;margin:0 0 6px;color:var(--ink-dim)">Recent audit-log events:</p><table>' + rows + "</table>";
  }

  function showToast(msg) {
    els.toast.textContent = msg;
    els.toast.hidden = false;
    clearTimeout(els.toast._t);
    els.toast._t = setTimeout(function () { els.toast.hidden = true; }, 2800);
  }

  /* ---- Toolbar & settings --------------------------------------------- */
  function initToolbar() {
    $("resetBtn").addEventListener("click", function () { Orbit.reset(); });
    $("settingsBtn").addEventListener("click", function () {
      var d = $("settingsDrawer"); d.hidden = !d.hidden;
    });
    $("settingsClose").addEventListener("click", function () { $("settingsDrawer").hidden = true; });
  }

  function initSettings() {
    var themeSel = $("themeSelect"),
        autoRot  = $("autoRotate"),
        pktTog   = $("packetToggle"),
        reduce   = $("reduceMotion");

    // load prefs
    var prefs = readPrefs();
    document.documentElement.setAttribute("data-theme", prefs.theme);
    themeSel.value = prefs.theme;
    autoRot.checked = prefs.autoRotate;
    pktTog.checked = prefs.packets;
    reduce.checked = prefs.reduceMotion;
    applyMotion(prefs);

    Orbit.setAutoRotate(prefs.autoRotate);

    themeSel.addEventListener("change", function () {
      document.documentElement.setAttribute("data-theme", themeSel.value);
      savePrefs();
    });
    autoRot.addEventListener("change", function () { Orbit.setAutoRotate(autoRot.checked); savePrefs(); });
    pktTog.addEventListener("change", function () { applyMotion(collect()); savePrefs(); });
    reduce.addEventListener("change", function () { applyMotion(collect()); savePrefs(); });

    function collect() {
      return { theme: themeSel.value, autoRotate: autoRot.checked, packets: pktTog.checked, reduceMotion: reduce.checked };
    }
    function applyMotion(p) {
      els.packets.classList.toggle("paused", !p.packets);
      document.documentElement.classList.toggle("reduce-motion", p.reduceMotion);
    }
    window._collectPrefs = collect;
  }

  function readPrefs() {
    var def = { theme: "dark", autoRotate: true, packets: true, reduceMotion: false };
    try {
      var raw = localStorage.getItem("secure_sims_prefs");
      if (raw) def = Object.assign(def, JSON.parse(raw));
    } catch (_) {}
    var cookieTheme = (document.cookie.match(/(?:^|; )sims_theme=(dark|light)/) || [])[1];
    if (cookieTheme) def.theme = cookieTheme;
    return def;
  }
  function savePrefs() {
    try {
      var p = window._collectPrefs ? window._collectPrefs() : null;
      if (p) {
        localStorage.setItem("secure_sims_prefs", JSON.stringify(p));
        document.cookie = "sims_theme=" + p.theme + ";path=/;max-age=34560000;SameSite=Lax";
      }
    } catch (_) {}
  }

  /* ---- Guided tour ----------------------------------------------------- */
  function initTour() {
    var steps = [
      { text: "Welcome to the Secure Student Information System walkthrough. Drag to orbit, scroll to zoom \u2014 or let this guided tour drive.", pose: { rx: -18, ry: -26, zoom: 1 } },
      { text: "This is the Browser: the client. Every request it sends is treated as untrusted until the server validates it.", hotspot: "browser", pose: { rx: -12, ry: -40, zoom: 1.2 } },
      { text: "Requests reach the PHP Application. It authenticates the user, enforces Role-Based Access Control, and uses PDO prepared statements.", hotspot: "php", pose: { rx: -14, ry: 0, zoom: 1.2 } },
      { text: "Data lives in MySQL: users, roles, permissions, students and a tamper-evident audit log \u2014 reached only through least-privilege accounts.", hotspot: "mysql", pose: { rx: -14, ry: 34, zoom: 1.2 } },
      { text: "Role-Based Access Control in action. As an Administrator, every module door unlocks.", role: "admin", pose: { rx: -30, ry: 10, zoom: 1.05 } },
      { text: "Switch to a Student and watch most doors lock \u2014 a student can only open Dashboard, Profile and Grades.", role: "student", pose: { rx: -30, ry: 10, zoom: 1.05 } },
      { text: "Confidentiality: packets travel inside a glowing shield. Unauthorised peek attempts bounce right off.", sec: "confidentiality", pose: { rx: -14, ry: -18, zoom: 1.15 } },
      { text: "Integrity: each packet carries a checksum seal. Watch \u2014 tampering cracks the seal and instantly raises an alert.", sec: "integrity", pose: { rx: -14, ry: -18, zoom: 1.15 } },
      { text: "Availability: the system stays lit and responsive, packets keep flowing so authorised users always get timely access.", sec: "availability", pose: { rx: -18, ry: -26, zoom: 1.05 } },
      { text: "That's the walkthrough: a PHP/MySQL system demonstrating Confidentiality, Integrity, Availability and RBAC. Free-explore whenever you're ready.", role: "admin", pose: { rx: -18, ry: -26, zoom: 1 } }
    ];

    var tour = $("tour"), stepEl = $("tourStep"), textEl = $("tourText");
    var idx = 0, active = false;

    function render() {
      var s = steps[idx];
      stepEl.textContent = "STEP " + (idx + 1) + " / " + steps.length;
      textEl.textContent = s.text;
      if (s.pose) Orbit.focus(s.pose);
      if (s.role) selectRole(s.role);
      if (s.sec) {
        var btn = els.secBtns.querySelector('[data-sec="' + s.sec + '"]');
        runSecurity(s.sec, btn);
      } else {
        setPacketMode("");
      }
      if (typeof s.hotspot === "string") {
        var arch = DATA.architecture.filter(function (a) { return a.code === s.hotspot; })[0];
        if (arch) showCaption(arch.title, arch.caption, s.hotspot === "mysql" ? studentsTable() : (s.hotspot === "php" ? auditPreview() : ""));
      }
    }

    function start() {
      active = true; idx = 0; tour.hidden = false;
      Orbit.setAutoRotate(false);
      render();
    }
    function stop() {
      active = false; tour.hidden = true;
      setPacketMode("");
      Orbit.setAutoRotate($("autoRotate").checked);
    }
    function next() { if (idx < steps.length - 1) { idx++; render(); } else { stop(); } }
    function prev() { if (idx > 0) { idx--; render(); } }

    $("tourBtn").addEventListener("click", start);
    $("tourNext").addEventListener("click", next);
    $("tourPrev").addEventListener("click", prev);
    $("tourExit").addEventListener("click", stop);
    document.addEventListener("keydown", function (e) {
      if (!active) return;
      if (e.key === "ArrowRight") next();
      else if (e.key === "ArrowLeft") prev();
      else if (e.key === "Escape") stop();
    });
  }
})();
