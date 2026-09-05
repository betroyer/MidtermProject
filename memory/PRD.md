# PRD — Secure SIMS (PHP/MySQL/XAMPP)

## Problem statement
A Student Information & Access Management System with role-based login and, alongside it,
an interactive CSS-3D "walkthrough" that visualizes the architecture + security concepts
(Confidentiality, Integrity, Availability, RBAC). Academic midterm. Delivered as PHP files
+ `database.sql` for XAMPP htdocs. Front page = login.

## Roles & flow
- Student: view own profile + own grades.
- Teacher: compute student grades (multiple quiz + activity scores + midterm + final, auto-computed), generate class reports.
- Admin: manage separate Teacher and Student lists, create blocks (class sections: 1 teacher, many students), assign teacher/students.
- Login: username auto-format `B_Delossantos`; password = school ID `2026-00200`.

## Validation rules
- Name: letters only. Phone: exactly 11 digits. Age: integer only.
- Email: valid format AND must end in `@gmail.com` (else "Incorrect email").
- School ID / password: format `2026-00200`.

## Security
- Passwords stored ONLY as bcrypt hashes (`password_hash`/`password_verify`); plaintext school ID never saved.
- All queries use PDO prepared statements. CSRF tokens on all POST forms. Session-fixation guard
  (`session_regenerate_id`). Role guards (`require_role`) enforce RBAC per page.

## Grade formula
final = Quizzes(avg)·20% + Activities(avg)·20% + Midterm·30% + Final·30%; passing ≥ 75.

## File map (added this iteration)
- `index.php` (login), `logout.php`, `walkthrough.php` (the 3D scene, moved from old index.php)
- `includes/` bootstrap.php, auth.php, validation.php, layout.php
- `admin/` index.php (blocks), teachers.php, students.php, actions.php
- `teacher/` index.php (roster), grade.php, actions.php, reports.php
- `student/` index.php (profile), grades.php
- `css/app.css` (dashboard/login), `css/scene.css` (walkthrough)
- `js/orbit.js`, `js/scene.js`, `api/data.php`, `config/db.php`, `database.sql`

## Verified (2026-06-03) via local `php -S` + MariaDB + curl
- Login for admin/teacher/student → correct dashboards; wrong password rejected; CSRF returns 419.
- RBAC: admin blocked from teacher pages (forbidden redirect); teacher cannot grade a student outside their block.
- Teacher grade compute correct (e.g. 91.00 Passed); upsert works (fixed PDO named-placeholder reuse).
- Admin validation: non-gmail email rejected with "Incorrect email"; valid create auto-generates username (e.g. R_Bautista).
- Student GWA/grades display correctly. All PHP `php -l` clean; JS `node --check` clean.
- NOTE: automated preview testing agent targets React/FastAPI ports (3000/8001) and cannot reach this PHP app; verified via curl + DB assertions + login screenshot instead.

## Backlog
- P2: admin edit (not just add/delete) for users; password reset by admin.
- P2: teacher attendance module. P2: printable/export PDF report.
- P2: link the 3D walkthrough RBAC scene to the real logged-in role.
