<?php
/** admin/actions.php — POST handlers for admin CRUD. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/avatar.php';

require_role('admin');
verify_csrf();

$action = $_POST['action'] ?? '';
$pdo = db();

function require_admin_perm(string $perm): void
{
    if (!role_can('admin', $perm)) {
        set_flash('error', 'Your role does not have permission to do that.');
        redirect(preg_replace('#^admin/#', '', home_for('admin')));
    }
}

/** Validate + build a user payload shared by teacher/student creation. */
function collect_user_input(array $in, array &$errors): array
{
    $first  = trim($in['first_name'] ?? '');
    $last   = trim($in['last_name'] ?? '');
    $email  = trim($in['email'] ?? '');
    $phone  = trim($in['phone'] ?? '');
    $age    = trim($in['age'] ?? '');
    $sid    = trim($in['school_id'] ?? '');

    if (!valid_name($first))      $errors[] = 'First name must contain letters only.';
    if (!valid_name($last))       $errors[] = 'Last name must contain letters only.';
    if (!valid_email($email))     $errors[] = 'Incorrect email — a valid @gmail.com address is required.';
    if (!valid_phone($phone))     $errors[] = 'Phone must be exactly 11 digits (numbers only).';
    if (!valid_age($age))         $errors[] = 'Age must be a whole number.';
    if (!valid_school_id($sid))   $errors[] = 'School ID / password must look like 2026-00200.';

    return compact('first', 'last', 'email', 'phone', 'age', 'sid');
}

if ($action === 'add_teacher' || $action === 'add_student') {
    require_admin_perm($action === 'add_teacher' ? 'users' : 'students');
    if ($action === 'add_student' && app_setting('records_locked', '0') === '1') {
        set_flash('error', 'Student records are locked in System Settings.');
        redirect('students.php');
    }
    $errors = [];
    $d = collect_user_input($_POST, $errors);
    $role = $action === 'add_teacher' ? 'teacher' : 'student';
    $block_id = null;

    $program_id = null;
    if ($role === 'student') {
        ensure_academics_schema($pdo);
        $block_id = (int)($_POST['block_id'] ?? 0);
        $program_id = (int)($_POST['program_id'] ?? 0);
        if ($block_id <= 0) $errors[] = 'Please choose a block for the student.';
        if ($program_id <= 0) $errors[] = 'Please choose a degree program for the student.';
        if ($program_id > 0) {
            $chk = $pdo->prepare('SELECT id FROM courses WHERE id = :id AND is_active = 1');
            $chk->execute([':id' => $program_id]);
            if (!$chk->fetchColumn()) {
                $errors[] = 'Selected program is invalid.';
            }
        }
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect($role === 'teacher' ? 'teachers.php' : 'students.php');
    }

    $username = generate_username($pdo, $d['first'], $d['last']);
    $hash = password_hash($d['sid'], PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (role, username, password_hash, school_id, first_name, last_name, email, phone, age, block_id, program_id)
         VALUES (:role,:u,:h,:sid,:f,:l,:e,:p,:a,:b,:prog)'
    );
    $stmt->execute([
        ':role' => $role, ':u' => $username, ':h' => $hash, ':sid' => $d['sid'],
        ':f' => $d['first'], ':l' => $d['last'], ':e' => $d['email'],
        ':p' => $d['phone'], ':a' => (int)$d['age'], ':b' => $block_id, ':prog' => $program_id,
    ]);
    $newId = (int)$pdo->lastInsertId();

    audit_log(
        $role === 'teacher' ? 'TEACHER_CREATED' : 'STUDENT_CREATED',
        ($role === 'teacher' ? 'teacher#' : 'student#') . $newId,
        $username
    );
    set_flash('ok', ucfirst($role) . " account created — username: {$username} (password = school ID).");
    redirect($role === 'teacher' ? 'teachers.php' : 'students.php');
}

if ($action === 'add_block') {
    require_admin_perm('dashboard');
    $name = trim($_POST['name'] ?? '');
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $errors = [];
    if ($name === '') $errors[] = 'Block name is required.';
    if ($teacher_id <= 0) $errors[] = 'Please assign a teacher to the block.';
    if ($department_id <= 0) $errors[] = 'Please select a department.';
    if ($course_id <= 0) $errors[] = 'Please select a course.';

    if (!$errors) {
        $chk = $pdo->prepare(
            'SELECT c.id FROM courses c
             JOIN departments d ON d.id = c.department_id
             WHERE c.id = :c AND c.department_id = :d AND c.is_active = 1 AND d.is_active = 1'
        );
        $chk->execute([':c' => $course_id, ':d' => $department_id]);
        if (!$chk->fetchColumn()) {
            $errors[] = 'Course must belong to the selected department.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO blocks (name, teacher_id, department_id, course_id) VALUES (:n,:t,:d,:c)'
            );
            $stmt->execute([
                ':n' => $name, ':t' => $teacher_id,
                ':d' => $department_id, ':c' => $course_id,
            ]);
            audit_log('BLOCK_CREATED', 'block:' . $name, 'teacher_id=' . $teacher_id . ';course_id=' . $course_id);
            set_flash('ok', "Block \"{$name}\" created.");
        } catch (PDOException $ex) {
            set_flash('error', 'A block with that name already exists.');
        }
    } else {
        set_flash('error', implode(' ', $errors));
    }
    redirect('index.php');
}

if ($action === 'update_block_academics') {
    require_admin_perm('dashboard');
    $id = (int)($_POST['id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $errors = [];
    if ($id <= 0) $errors[] = 'Invalid block.';
    if ($department_id <= 0) $errors[] = 'Please select a department.';
    if ($course_id <= 0) $errors[] = 'Please select a course.';

    if (!$errors) {
        $chk = $pdo->prepare(
            'SELECT c.id FROM courses c WHERE c.id = :c AND c.department_id = :d AND c.is_active = 1'
        );
        $chk->execute([':c' => $course_id, ':d' => $department_id]);
        if (!$chk->fetchColumn()) {
            $errors[] = 'Course must belong to the selected department.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE blocks SET department_id = :d, course_id = :c WHERE id = :id'
        );
        $stmt->execute([':d' => $department_id, ':c' => $course_id, ':id' => $id]);
        audit_log('BLOCK_ACADEMICS_UPDATED', 'block:' . $id, 'dept=' . $department_id . ';course=' . $course_id);
        set_flash('ok', 'Block department and course updated.');
    } else {
        set_flash('error', implode(' ', $errors));
    }
    redirect('index.php' . (!empty($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : ''));
}

if ($action === 'delete_user') {
    $id = (int)($_POST['id'] ?? 0);
    $back = $_POST['back'] ?? 'index.php';
    $needed = $back === 'teachers.php' ? 'users' : 'students';
    require_admin_perm($needed);
    // never allow deleting yourself
    if ($id === (int)current_user()['id']) {
        set_flash('error', 'You cannot delete your own account.');
    } else {
        if ($needed === 'students' && app_setting('records_locked', '0') === '1') {
            set_flash('error', 'Student records are locked in System Settings.');
            redirect($back);
        }
        $who = $pdo->prepare('SELECT username, role FROM users WHERE id = :id');
        $who->execute([':id' => $id]);
        $deleted = $who->fetch();
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        // detach from any block they taught
        $pdo->prepare('UPDATE blocks SET teacher_id = NULL WHERE teacher_id = :id')->execute([':id' => $id]);
        if ($deleted) {
            audit_log('USER_DELETED', $deleted['role'] . '#' . $id, $deleted['username']);
        }
        set_flash('ok', 'Account removed.');
    }
    redirect($back);
}

if ($action === 'update_student') {
    require_admin_perm('students');
    if (app_setting('records_locked', '0') === '1') {
        set_flash('error', 'Student records are locked in System Settings.');
        redirect('students.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    $errors = [];
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $age   = trim($_POST['age'] ?? '');
    $sid   = trim($_POST['school_id'] ?? '');
    ensure_academics_schema($pdo);
    $block_id = (int)($_POST['block_id'] ?? 0);
    $program_id = (int)($_POST['program_id'] ?? 0);

    if (!valid_name($first))  $errors[] = 'First name must contain letters only.';
    if (!valid_name($last))   $errors[] = 'Last name must contain letters only.';
    if (!valid_email($email)) $errors[] = 'Incorrect email — a valid @gmail.com address is required.';
    if (!valid_phone($phone)) $errors[] = 'Phone must be exactly 11 digits (numbers only).';
    if (!valid_age($age))     $errors[] = 'Age must be a whole number.';
    if ($block_id <= 0)       $errors[] = 'Please choose a block for the student.';
    if ($program_id <= 0)     $errors[] = 'Please choose a degree program for the student.';
    if ($sid !== '' && !valid_school_id($sid)) {
        $errors[] = 'School ID / password must look like 2026-00200.';
    }
    if ($program_id > 0) {
        $chk = $pdo->prepare('SELECT id FROM courses WHERE id = :id AND is_active = 1');
        $chk->execute([':id' => $program_id]);
        if (!$chk->fetchColumn()) {
            $errors[] = 'Selected program is invalid.';
        }
    }

    $exists = $pdo->prepare('SELECT id, username FROM users WHERE id = :id AND role = "student"');
    $exists->execute([':id' => $id]);
    $row = $exists->fetch();
    if (!$row) {
        $errors[] = 'Student record not found.';
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('student_view.php?id=' . $id . '&mode=edit');
    }

    if ($sid !== '') {
        $stmt = $pdo->prepare(
            'UPDATE users SET first_name=:f, last_name=:l, email=:e, phone=:p, age=:a, block_id=:b, program_id=:prog,
                    password_hash=:h, school_id=:sid
             WHERE id=:id AND role="student"'
        );
        $stmt->execute([
            ':f' => $first, ':l' => $last, ':e' => $email, ':p' => $phone,
            ':a' => (int)$age, ':b' => $block_id, ':prog' => $program_id,
            ':h' => password_hash($sid, PASSWORD_BCRYPT),
            ':sid' => $sid, ':id' => $id,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE users SET first_name=:f, last_name=:l, email=:e, phone=:p, age=:a, block_id=:b, program_id=:prog
             WHERE id=:id AND role="student"'
        );
        $stmt->execute([
            ':f' => $first, ':l' => $last, ':e' => $email, ':p' => $phone,
            ':a' => (int)$age, ':b' => $block_id, ':prog' => $program_id, ':id' => $id,
        ]);
    }

    audit_log('STUDENT_UPDATED', 'student#' . $id, $row['username'] . ($sid !== '' ? ' (password reset)' : ''));
    set_flash('ok', 'Student record updated.');
    redirect('student_view.php?id=' . $id);
}

if ($action === 'update_teacher') {
    require_admin_perm('users');
    $id = (int)($_POST['id'] ?? 0);
    $errors = [];
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $age   = trim($_POST['age'] ?? '');
    $sid   = trim($_POST['school_id'] ?? '');

    if (!valid_name($first))  $errors[] = 'First name must contain letters only.';
    if (!valid_name($last))   $errors[] = 'Last name must contain letters only.';
    if (!valid_email($email)) $errors[] = 'Incorrect email — a valid @gmail.com address is required.';
    if (!valid_phone($phone)) $errors[] = 'Phone must be exactly 11 digits (numbers only).';
    if (!valid_age($age))     $errors[] = 'Age must be a whole number.';
    if ($sid !== '' && !valid_school_id($sid)) {
        $errors[] = 'School ID / password must look like 2026-00200.';
    }

    $exists = $pdo->prepare('SELECT id, username FROM users WHERE id = :id AND role = "teacher"');
    $exists->execute([':id' => $id]);
    $row = $exists->fetch();
    if (!$row) {
        $errors[] = 'Teacher account not found.';
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('teacher_view.php?id=' . $id . '&mode=edit');
    }

    if ($sid !== '') {
        $stmt = $pdo->prepare(
            'UPDATE users SET first_name=:f, last_name=:l, email=:e, phone=:p, age=:a, password_hash=:h, school_id=:sid
             WHERE id=:id AND role="teacher"'
        );
        $stmt->execute([
            ':f' => $first, ':l' => $last, ':e' => $email, ':p' => $phone,
            ':a' => (int)$age, ':h' => password_hash($sid, PASSWORD_BCRYPT), ':sid' => $sid, ':id' => $id,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE users SET first_name=:f, last_name=:l, email=:e, phone=:p, age=:a
             WHERE id=:id AND role="teacher"'
        );
        $stmt->execute([
            ':f' => $first, ':l' => $last, ':e' => $email, ':p' => $phone,
            ':a' => (int)$age, ':id' => $id,
        ]);
    }

    audit_log('TEACHER_UPDATED', 'teacher#' . $id, $row['username'] . ($sid !== '' ? ' (password reset)' : ''));
    set_flash('ok', 'Teacher information updated.');
    redirect('teacher_view.php?id=' . $id);
}

if ($action === 'set_user_active') {
    $id = (int)($_POST['id'] ?? 0);
    $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $back = $_POST['back'] ?? 'index.php';

    $who = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id');
    $who->execute([':id' => $id]);
    $target = $who->fetch();
    if (!$target) {
        set_flash('error', 'Account not found.');
        redirect($back);
    }
    if ($target['role'] === 'admin') {
        set_flash('error', 'Admin accounts cannot be deactivated here.');
        redirect($back);
    }
    if ((int)$target['id'] === (int)current_user()['id']) {
        set_flash('error', 'You cannot deactivate your own account.');
        redirect($back);
    }

    $needed = $target['role'] === 'teacher' ? 'users' : 'students';
    require_admin_perm($needed);
    if ($target['role'] === 'student' && app_setting('records_locked', '0') === '1') {
        set_flash('error', 'Student records are locked in System Settings.');
        redirect($back);
    }

    ensure_user_active_column($pdo);
    $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :id')->execute([':a' => $active, ':id' => $id]);
    audit_log(
        $active ? 'USER_ACTIVATED' : 'USER_DEACTIVATED',
        $target['role'] . '#' . $id,
        $target['username']
    );
    set_flash('ok', $active ? 'Account activated.' : 'Account deactivated. They can no longer sign in.');
    redirect($back);
}

if ($action === 'save_general') {
    require_admin_perm('roles');
    $name = trim($_POST['school_name'] ?? '');
    $year = trim($_POST['school_year'] ?? '');
    $support = trim($_POST['support_email'] ?? '');
    $loginMsg = trim($_POST['login_message'] ?? '');
    $locked = isset($_POST['records_locked']) ? '1' : '0';
    $errors = [];
    if ($name === '' || strlen($name) > 80) {
        $errors[] = 'System name is required (max 80 characters).';
    }
    if ($year === '' || strlen($year) > 20) {
        $errors[] = 'School year is required (max 20 characters).';
    }
    if (!valid_email($support)) {
        $errors[] = 'Support email must be a valid @gmail.com address.';
    }
    if ($loginMsg === '' || strlen($loginMsg) > 160) {
        $errors[] = 'Login message is required (max 160 characters).';
    }
    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('settings.php?section=general');
    }
    set_app_setting('school_name', $name);
    set_app_setting('school_year', $year);
    set_app_setting('support_email', $support);
    set_app_setting('login_message', $loginMsg);
    set_app_setting('records_locked', $locked);
    audit_log('SETTINGS_UPDATED', 'app_settings', 'System settings saved');
    set_flash('ok', 'System settings saved.');
    redirect('settings.php?section=general');
}

if ($action === 'save_grading') {
    require_admin_perm('roles');
    ensure_grading_schema($pdo);

    $wq = 0.0;
    $wa = 0.0;
    $wm = (float)($_POST['weight_midterm'] ?? 0);
    $wf = (float)($_POST['weight_final'] ?? 0);
    $pass = (float)($_POST['passing_point'] ?? 3);
    $points = $_POST['scale_point'] ?? [];
    $mins = $_POST['scale_min'] ?? [];
    $maxs = $_POST['scale_max'] ?? [];
    $descs = $_POST['scale_desc'] ?? [];

    $errors = [];
    if ($wm < 0 || $wf < 0 || ($wm + $wf) <= 0) {
        $errors[] = 'Midterm and final weights must be zero or positive and total more than 0.';
    }
    if ($pass < 1 || $pass > 5) {
        $errors[] = 'Passing grade point must be between 1.00 and 5.00.';
    }
    if (!is_array($points) || count($points) < 1) {
        $errors[] = 'Add at least one grading scale row.';
    }

    $rows = [];
    $n = is_array($points) ? count($points) : 0;
    for ($i = 0; $i < $n; $i++) {
        $gp = (float)($points[$i] ?? 0);
        $min = (float)($mins[$i] ?? 0);
        $max = (float)($maxs[$i] ?? 0);
        $desc = trim((string)($descs[$i] ?? ''));
        if ($gp < 1 || $gp > 5) {
            $errors[] = 'Each grade point must be between 1.00 and 5.00.';
            break;
        }
        if ($min < 0 || $max > 100 || $min > $max) {
            $errors[] = 'Each scale row needs a valid min/max percent (0–100, min ≤ max).';
            break;
        }
        if (strlen($desc) > 40) {
            $errors[] = 'Descriptions must be 40 characters or fewer.';
            break;
        }
        $rows[] = compact('gp', 'min', 'max', 'desc');
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('settings.php?section=grading');
    }

    // Sort by grade point ascending (1.0 first, 5.0 last)
    usort($rows, static fn($a, $b) => $a['gp'] <=> $b['gp']);

    set_app_setting('weight_quiz', (string)$wq);
    set_app_setting('weight_activity', (string)$wa);
    set_app_setting('weight_midterm', (string)$wm);
    set_app_setting('weight_final', (string)$wf);
    set_app_setting('passing_point', number_format($pass, 2, '.', ''));

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM grading_scale');
        $ins = $pdo->prepare(
            'INSERT INTO grading_scale (grade_point, min_percent, max_percent, description, sort_order)
             VALUES (:gp, :min, :max, :d, :s)'
        );
        foreach ($rows as $i => $r) {
            $ins->execute([
                ':gp' => $r['gp'], ':min' => $r['min'], ':max' => $r['max'],
                ':d' => $r['desc'], ':s' => $i + 1,
            ]);
        }
        $pdo->commit();
        audit_log('GRADING_UPDATED', 'grading_scale', count($rows) . ' bands, weights saved');
        set_flash('ok', 'Grading system saved. New grade computations will use this scale.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        set_flash('error', 'Could not save the grading system.');
    }
    redirect('settings.php?section=grading');
}

if ($action === 'save_permissions') {
    require_admin_perm('roles');
    ensure_rbac_schema($pdo);

    $roles = $pdo->query('SELECT id, code FROM roles')->fetchAll();
    $permRows = $pdo->query('SELECT id, code FROM permissions')->fetchAll();
    $permIdByCode = [];
    foreach ($permRows as $p) {
        $permIdByCode[$p['code']] = (int)$p['id'];
    }

    $posted = $_POST['perm'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM role_permissions');
        $ins = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)'
        );
        foreach ($roles as $r) {
            $codes = $posted[$r['code']] ?? [];
            if (!is_array($codes)) {
                $codes = [];
            }
            $codes = array_unique(array_map('strval', $codes));
            foreach (locked_permissions_for($r['code']) as $lockedPerm) {
                $codes[] = $lockedPerm;
            }
            $codes = array_unique($codes);
            foreach ($codes as $code) {
                if (!isset($permIdByCode[$code])) {
                    continue;
                }
                $ins->execute([':r' => (int)$r['id'], ':p' => $permIdByCode[$code]]);
            }
        }
        $pdo->commit();
        audit_log('ROLE_MATRIX_UPDATED', 'roles', 'Permission matrix saved');
        set_flash('ok', 'Roles & permissions saved. Nav and page access now follow the new matrix.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        set_flash('error', 'Could not save permissions. Please try again.');
    }
    redirect('settings.php?section=roles');
}

if ($action === 'add_department' || $action === 'update_department') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : ($action === 'add_department' ? 1 : 0);
    if ($action === 'add_department') {
        $isActive = 1;
    }

    $errors = [];
    if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
        $errors[] = 'Department code must be 2–20 letters, numbers, _ or -.';
    }
    if ($name === '' || strlen($name) > 160) {
        $errors[] = 'College name is required (max 160 characters).';
    }
    if (strlen($description) > 255) {
        $errors[] = 'Description must be 255 characters or fewer.';
    }
    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('academics.php?section=departments' . ($id ? '&edit=' . $id : ''));
    }

    try {
        if ($action === 'add_department') {
            $stmt = $pdo->prepare(
                'INSERT INTO departments (code, name, description, is_active) VALUES (:c,:n,:d,:a)'
            );
            $stmt->execute([':c' => $code, ':n' => $name, ':d' => $description, ':a' => $isActive]);
            audit_log('DEPARTMENT_CREATED', 'department:' . $code, $name);
            set_flash('ok', "Department {$code} created.");
        } else {
            $stmt = $pdo->prepare(
                'UPDATE departments SET code=:c, name=:n, description=:d, is_active=:a WHERE id=:id'
            );
            $stmt->execute([':c' => $code, ':n' => $name, ':d' => $description, ':a' => $isActive, ':id' => $id]);
            audit_log('DEPARTMENT_UPDATED', 'department#' . $id, $code);
            set_flash('ok', "Department {$code} updated.");
        }
    } catch (Throwable $e) {
        set_flash('error', 'Could not save department. Code may already exist.');
    }
    redirect('academics.php?section=departments');
}

if ($action === 'delete_department') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $row = $pdo->prepare('SELECT code FROM departments WHERE id = :id');
    $row->execute([':id' => $id]);
    $code = $row->fetchColumn();
    if ($code) {
        $pdo->prepare('DELETE FROM departments WHERE id = :id')->execute([':id' => $id]);
        audit_log('DEPARTMENT_DELETED', 'department#' . $id, (string)$code);
        set_flash('ok', "Department {$code} deleted.");
    } else {
        set_flash('error', 'Department not found.');
    }
    redirect('academics.php?section=departments');
}

if ($action === 'add_course' || $action === 'update_course') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $units = (float)($_POST['units'] ?? 3);
    $isActive = isset($_POST['is_active']) ? 1 : ($action === 'add_course' ? 1 : 0);
    if ($action === 'add_course') {
        $isActive = 1;
    }

    $errors = [];
    if ($departmentId <= 0) {
        $errors[] = 'Please choose a college.';
    }
    if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
        $errors[] = 'Program code must be 2–40 letters, numbers, _ or -.';
    }
    if ($name === '' || strlen($name) > 200) {
        $errors[] = 'Program name is required (max 200 characters).';
    }
    if ($units < 0 || $units > 12) {
        $errors[] = 'Units must be between 0 and 12.';
    }
    if (strlen($description) > 255) {
        $errors[] = 'Description must be 255 characters or fewer.';
    }
    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('academics.php?section=courses' . ($id ? '&edit=' . $id : ''));
    }

    try {
        if ($action === 'add_course') {
            $stmt = $pdo->prepare(
                'INSERT INTO courses (department_id, code, name, description, units, is_active)
                 VALUES (:d,:c,:n,:desc,:u,:a)'
            );
            $stmt->execute([
                ':d' => $departmentId, ':c' => $code, ':n' => $name,
                ':desc' => $description, ':u' => $units, ':a' => $isActive,
            ]);
            audit_log('COURSE_CREATED', 'course:' . $code, $name);
            set_flash('ok', "Course {$code} created.");
        } else {
            $stmt = $pdo->prepare(
                'UPDATE courses SET department_id=:d, code=:c, name=:n, description=:desc, units=:u, is_active=:a
                 WHERE id=:id'
            );
            $stmt->execute([
                ':d' => $departmentId, ':c' => $code, ':n' => $name,
                ':desc' => $description, ':u' => $units, ':a' => $isActive, ':id' => $id,
            ]);
            audit_log('COURSE_UPDATED', 'course#' . $id, $code);
            set_flash('ok', "Course {$code} updated.");
        }
    } catch (Throwable $e) {
        set_flash('error', 'Could not save course. Code may already exist.');
    }
    redirect('academics.php?section=courses');
}

if ($action === 'delete_course') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $row = $pdo->prepare('SELECT code FROM courses WHERE id = :id');
    $row->execute([':id' => $id]);
    $code = $row->fetchColumn();
    if ($code) {
        $pdo->prepare('DELETE FROM courses WHERE id = :id')->execute([':id' => $id]);
        audit_log('COURSE_DELETED', 'course#' . $id, (string)$code);
        set_flash('ok', "Program {$code} deleted.");
    } else {
        set_flash('error', 'Program not found.');
    }
    redirect('academics.php?section=courses');
}

if ($action === 'add_subject' || $action === 'update_subject') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $units = (float)($_POST['units'] ?? 3);
    $isActive = isset($_POST['is_active']) ? 1 : ($action === 'add_subject' ? 1 : 0);
    if ($action === 'add_subject') {
        $isActive = 1;
    }

    $errors = [];
    if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
        $errors[] = 'Subject code must be 2–40 letters, numbers, _ or -.';
    }
    if ($name === '' || strlen($name) > 160) {
        $errors[] = 'Subject name is required (max 160 characters).';
    }
    if ($units < 0.5 || $units > 12) {
        $errors[] = 'Units must be between 0.5 and 12.';
    }
    if ($departmentId > 0) {
        $chk = $pdo->prepare('SELECT id FROM departments WHERE id = :id');
        $chk->execute([':id' => $departmentId]);
        if (!$chk->fetchColumn()) {
            $errors[] = 'Invalid college.';
        }
    } else {
        $departmentId = null;
    }
    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('academics.php?section=subjects' . ($id ? '&edit=' . $id : ''));
    }

    try {
        if ($action === 'add_subject') {
            $stmt = $pdo->prepare(
                'INSERT INTO subjects (code, name, department_id, units, is_active) VALUES (:c,:n,:d,:u,:a)'
            );
            $stmt->execute([':c' => $code, ':n' => $name, ':d' => $departmentId, ':u' => $units, ':a' => $isActive]);
            audit_log('SUBJECT_CREATED', 'subject:' . $code, $name);
            set_flash('ok', "Subject {$code} created.");
        } else {
            $stmt = $pdo->prepare(
                'UPDATE subjects SET code=:c, name=:n, department_id=:d, units=:u, is_active=:a WHERE id=:id'
            );
            $stmt->execute([
                ':c' => $code, ':n' => $name, ':d' => $departmentId, ':u' => $units,
                ':a' => $isActive, ':id' => $id,
            ]);
            audit_log('SUBJECT_UPDATED', 'subject#' . $id, $code);
            set_flash('ok', "Subject {$code} updated.");
        }
    } catch (Throwable $e) {
        set_flash('error', 'Could not save subject. Code may already exist.');
    }
    redirect('academics.php?section=subjects');
}

if ($action === 'delete_subject') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $row = $pdo->prepare('SELECT code FROM subjects WHERE id = :id');
    $row->execute([':id' => $id]);
    $code = $row->fetchColumn();
    if ($code) {
        $pdo->prepare('DELETE FROM subjects WHERE id = :id')->execute([':id' => $id]);
        audit_log('SUBJECT_DELETED', 'subject#' . $id, (string)$code);
        set_flash('ok', "Subject {$code} deleted.");
    } else {
        set_flash('error', 'Subject not found.');
    }
    redirect('academics.php?section=subjects');
}

if ($action === 'add_offering' || $action === 'update_offering') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : ($action === 'add_offering' ? 1 : 0);
    if ($action === 'add_offering') {
        $isActive = 1;
    }

    $errors = [];
    if ($subjectId <= 0) $errors[] = 'Please select a subject.';
    if ($teacherId <= 0) $errors[] = 'Please select a teacher.';
    if (strlen($name) > 80) $errors[] = 'Section label must be 80 characters or fewer.';
    if (!$errors) {
        $chk = $pdo->prepare('SELECT id FROM subjects WHERE id = :id');
        $chk->execute([':id' => $subjectId]);
        if (!$chk->fetchColumn()) $errors[] = 'Invalid subject.';
        $chk = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "teacher"');
        $chk->execute([':id' => $teacherId]);
        if (!$chk->fetchColumn()) $errors[] = 'Invalid teacher.';
    }
    if ($errors) {
        set_flash('error', implode(' ', $errors));
        redirect('academics.php?section=offerings' . ($id ? '&edit=' . $id : ''));
    }

    try {
        if ($action === 'add_offering') {
            $stmt = $pdo->prepare(
                'INSERT INTO subject_offerings (subject_id, teacher_id, name, is_active) VALUES (:s,:t,:n,:a)'
            );
            $stmt->execute([':s' => $subjectId, ':t' => $teacherId, ':n' => $name, ':a' => $isActive]);
            audit_log('OFFERING_CREATED', 'offering#' . $pdo->lastInsertId(), 'subject=' . $subjectId);
            set_flash('ok', 'Offering created.');
        } else {
            $stmt = $pdo->prepare(
                'UPDATE subject_offerings SET subject_id=:s, teacher_id=:t, name=:n, is_active=:a WHERE id=:id'
            );
            $stmt->execute([
                ':s' => $subjectId, ':t' => $teacherId, ':n' => $name, ':a' => $isActive, ':id' => $id,
            ]);
            audit_log('OFFERING_UPDATED', 'offering#' . $id, 'subject=' . $subjectId);
            set_flash('ok', 'Offering updated.');
        }
    } catch (Throwable $e) {
        set_flash('error', 'Could not save offering.');
    }
    redirect('academics.php?section=offerings');
}

if ($action === 'delete_offering') {
    require_admin_perm('academics');
    ensure_academics_schema($pdo);
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM subject_offerings WHERE id = :id')->execute([':id' => $id]);
        audit_log('OFFERING_DELETED', 'offering#' . $id, '');
        set_flash('ok', 'Offering deleted.');
    } else {
        set_flash('error', 'Offering not found.');
    }
    redirect('academics.php?section=offerings');
}

if ($action === 'enroll_student') {
    require_admin_perm('students');
    ensure_academics_schema($pdo);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $offeringId = (int)($_POST['offering_id'] ?? 0);
    $back = 'student_view.php?id=' . $studentId;
    if ($studentId <= 0 || $offeringId <= 0) {
        set_flash('error', 'Student and offering are required.');
        redirect($back);
    }
    $chk = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "student"');
    $chk->execute([':id' => $studentId]);
    if (!$chk->fetchColumn()) {
        set_flash('error', 'Student not found.');
        redirect('students.php');
    }
    $chk = $pdo->prepare('SELECT id FROM subject_offerings WHERE id = :id AND is_active = 1');
    $chk->execute([':id' => $offeringId]);
    if (!$chk->fetchColumn()) {
        set_flash('error', 'Offering not found or inactive.');
        redirect($back);
    }
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO enrollments (student_id, offering_id) VALUES (:s,:o)'
        )->execute([':s' => $studentId, ':o' => $offeringId]);
        audit_log('ENROLLMENT_CREATED', 'student#' . $studentId, 'offering#' . $offeringId);
        set_flash('ok', 'Student enrolled in offering.');
    } catch (Throwable $e) {
        set_flash('error', 'Could not enroll student.');
    }
    redirect($back);
}

if ($action === 'unenroll_student') {
    require_admin_perm('students');
    ensure_academics_schema($pdo);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $offeringId = (int)($_POST['offering_id'] ?? 0);
    $back = 'student_view.php?id=' . $studentId;
    $pdo->prepare(
        'DELETE FROM enrollments WHERE student_id = :s AND offering_id = :o'
    )->execute([':s' => $studentId, ':o' => $offeringId]);
    audit_log('ENROLLMENT_REMOVED', 'student#' . $studentId, 'offering#' . $offeringId);
    set_flash('ok', 'Enrollment removed.');
    redirect($back);
}

if ($action === 'upload_avatar') {
    ensure_user_avatar_column($pdo);
    $uid = (int)$_SESSION['user']['id'];
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $uid]);
    $old = $stmt->fetchColumn() ?: null;
    [$filename, $error] = store_avatar_upload($_FILES['avatar'] ?? [], $uid, $old);
    if ($error) {
        set_flash('error', $error);
        redirect('index.php');
    }
    $pdo->prepare('UPDATE users SET avatar = :a WHERE id = :id')->execute([':a' => $filename, ':id' => $uid]);
    $_SESSION['user']['avatar'] = $filename;
    audit_log('AVATAR_UPDATED', 'admin#' . $uid, 'Profile picture uploaded');
    set_flash('ok', 'Profile picture updated.');
    redirect('index.php');
}

if ($action === 'remove_avatar') {
    ensure_user_avatar_column($pdo);
    $uid = (int)$_SESSION['user']['id'];
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $uid]);
    $old = $stmt->fetchColumn() ?: null;
    delete_avatar_file($old);
    $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = :id')->execute([':id' => $uid]);
    $_SESSION['user']['avatar'] = null;
    audit_log('AVATAR_REMOVED', 'admin#' . $uid, 'Profile picture removed');
    set_flash('ok', 'Profile picture removed.');
    redirect('index.php');
}

redirect('index.php');
