# Secure SIMS — 3D Security Walkthrough (PHP / MySQL / XAMPP)

An interactive, free-explore **CSS-3D** visualization that walks through a Student
Information & Access Management System's architecture and security concepts:
**Confidentiality, Integrity, Availability** and **Role-Based Access Control (RBAC)**.

No frameworks, no libraries — plain HTML5 / CSS3 / vanilla JS on a PHP + MySQL back end.

---

## Quick start (XAMPP)

1. **Start XAMPP** → run **Apache** and **MySQL**.
2. **Import the database**
   - Open `http://localhost/phpmyadmin`
   - Click **Import** → choose **`database.sql`** → **Go**
   - This creates the `secure_sims` database with schema + seed data.
3. **Copy the project** into your web root:
   - Put this whole folder inside `xampp/htdocs/`, e.g. `xampp/htdocs/secure-sims/`
4. **Open it** in a modern browser:
   - `http://localhost/secure-sims/`  (or `.../index.php`)

That's it. The landing scene loads the 3D architecture and gently auto-rotates.

---

## How to use / demo

- **Drag** to orbit • **Scroll** to zoom • **Click** any glowing part for a caption.
- **Guided Tour** (top-right): auto-walks the whole story — a safe fallback if nerves
  hit during the presentation. Use **Back / Next** or the arrow keys, **Esc** to exit.
- **Reset View**: returns the camera to the default angle if the scene gets spun around.
- **Impersonate role** (bottom-left): Admin / Teacher / Student — watch module "doors"
  unlock or lock based on that role's permissions.
- **Security concepts** (bottom-right):
  - *Confidentiality* → packets wrapped in a glowing shield; a peek attempt bounces off.
  - *Integrity* → packets carry a checksum seal; tampering cracks it and raises an alert.
  - *Availability* → the system stays lit and responsive as packets keep flowing.
- **Settings** (⚙): switch **Light / Dark** theme, toggle auto-rotate, packet animation,
  and reduce-motion. Preferences are saved on the device.

---

## File structure

```
index.php        Main page + 3D scene markup (served by PHP)
css/scene.css    CSS-3D transforms, animations, control-room / light themes
js/orbit.js      Mouse + touch drag orbit, scroll zoom, smooth easing
js/scene.js      Packet flow, RBAC logic, hotspots, guided tour, settings
api/data.php     Returns roles/permissions/students/audit as JSON (PDO)
config/db.php    MySQL connection (PDO + prepared statements)
database.sql     Schema + seed data for phpMyAdmin import
```

---

## Notes

- **Graceful fallback:** if MySQL is unreachable, both `api/data.php` and the front end
  fall back to identical built-in sample data, so the demo **never blank-screens**.
- **Security-themed code:** all DB reads use **PDO prepared statements**, even for the
  illustrative sample rows.
- **Data is illustrative** (seeded sample rows), not a live production student system.
- **DB config** lives in `config/db.php` (defaults to XAMPP: host `127.0.0.1`, user
  `root`, empty password, database `secure_sims`). Adjust there if your setup differs.
- Tested target browsers: recent Chrome / Edge / Firefox. Works with mouse or trackpad/touch.
