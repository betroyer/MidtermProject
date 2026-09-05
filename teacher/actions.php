<?php
/** teacher/actions.php — teacher POST handlers (avatar + grade save + report submit). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/avatar.php';

$user = require_role('teacher');
verify_csrf();
$pdo = db();
$tid = (int)$user['id'];
$action = $_POST['action'] ?? '';

if ($action === 'upload_avatar') {
    ensure_user_avatar_column($pdo);
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $tid]);
    $old = $stmt->fetchColumn() ?: null;
    [$filename, $error] = store_avatar_upload($_FILES['avatar'] ?? [], $tid, $old);
    if ($error) {
        set_flash('error', $error);
        redirect('profile.php');
    }
    $pdo->prepare('UPDATE users SET avatar = :a WHERE id = :id')->execute([':a' => $filename, ':id' => $tid]);
    $_SESSION['user']['avatar'] = $filename;
    audit_log('AVATAR_UPDATED', 'teacher#' . $tid, 'Profile picture uploaded');
    set_flash('ok', 'Profile picture updated.');
    redirect('profile.php');
}

if ($action === 'remove_avatar') {
    ensure_user_avatar_column($pdo);
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $tid]);
    $old = $stmt->fetchColumn() ?: null;
    delete_avatar_file($old);
    $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = :id')->execute([':id' => $tid]);
    $_SESSION['user']['avatar'] = null;
    audit_log('AVATAR_REMOVED', 'teacher#' . $tid, 'Profile picture removed');
    set_flash('ok', 'Profile picture removed.');
    redirect('profile.php');
}

if ($action === 'submit_grade_report') {
    if (!role_can('teacher', 'reports')) {
        set_flash('error', 'Your role does not have permission to submit reports.');
        redirect('reports.php');
    }
    ensure_academics_schema($pdo);
    $offeringId = (int)($_POST['offering_id'] ?? 0);
    $blockId = (int)($_POST['block_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $off = $pdo->prepare(
        'SELECT o.*, s.code AS subject_code, s.name AS subject_name
         FROM subject_offerings o
         JOIN subjects s ON s.id = o.subject_id
         WHERE o.id = :id AND o.teacher_id = :t AND o.is_active = 1'
    );
    $off->execute([':id' => $offeringId, ':t' => $tid]);
    $offering = $off->fetch();
    if (!$offering) {
        set_flash('error', 'Offering not found or not yours.');
        redirect('reports.php');
    }

    if ($blockId <= 0) {
        set_flash('error', 'Choose a block before submitting a grade record.');
        redirect('reports.php');
    }

    $completion = offering_block_grade_completion($pdo, $tid, $offeringId, $blockId);
    if (!$completion['complete']) {
        $names = array_map(static fn($m) => $m['name'], $completion['missing']);
        $hint = $names ? (' Missing: ' . implode(', ', array_slice($names, 0, 5))
            . (count($names) > 5 ? '…' : '') . '.') : '';
        set_flash(
            'error',
            'Grade record is not ready. Compute grades for every student in this block first ('
            . $completion['graded'] . '/' . $completion['total'] . ' done).' . $hint
        );
        redirect('reports.php?block_id=' . $blockId);
    }

    $sql = 'SELECT u.id AS student_id, CONCAT(u.first_name," ",u.last_name) AS name, u.username,
                   b.name AS block_name,
                   g.subject, g.midterm, g.final_exam, g.final_grade, g.grade_point, g.remark
            FROM enrollments e
            JOIN users u ON u.id = e.student_id
            LEFT JOIN blocks b ON b.id = u.block_id
            LEFT JOIN grades g ON g.student_id = u.id AND g.offering_id = e.offering_id
            WHERE e.offering_id = :oid AND u.block_id = :bid
            ORDER BY u.last_name, u.first_name';
    $rows = $pdo->prepare($sql);
    $rows->execute([':oid' => $offeringId, ':bid' => $blockId]);
    $snapshot = $rows->fetchAll();
    $title = trim($offering['subject_code'] . ' — ' . $offering['subject_name']
        . ($offering['name'] !== '' ? ' · ' . $offering['name'] : ''));
    $bname = $pdo->prepare('SELECT name FROM blocks WHERE id = :id');
    $bname->execute([':id' => $blockId]);
    $bn = $bname->fetchColumn();
    if ($bn) {
        $title .= ' · ' . $bn;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO grade_reports (teacher_id, offering_id, title, status, snapshot_json, note)
         VALUES (:t,:o,:title,"submitted",:json,:note)'
    );
    $stmt->execute([
        ':t' => $tid,
        ':o' => $offeringId,
        ':title' => $title,
        ':json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ':note' => mb_substr($note, 0, 255),
    ]);
    audit_log('GRADE_REPORT_SUBMITTED', 'offering#' . $offeringId, $title);
    set_flash('ok', 'Grade report submitted to admin.');
    redirect('reports.php?block_id=' . $blockId);
}

if ($action !== 'save_grade') {
    redirect('index.php');
}

if (!role_can('teacher', 'grades')) {
    set_flash('error', 'Your role does not have permission to update grades.');
    redirect('index.php');
}

ensure_grading_schema($pdo);
ensure_academics_schema($pdo);

$student_id = (int)($_POST['student_id'] ?? 0);
$offering_id = (int)($_POST['offering_id'] ?? 0);
$midRaw = trim((string)($_POST['midterm'] ?? ''));
$finRaw = trim((string)($_POST['final_exam'] ?? ''));

$off = $pdo->prepare(
    'SELECT o.id, s.name AS subject_name
     FROM enrollments e
     JOIN subject_offerings o ON o.id = e.offering_id
     JOIN subjects s ON s.id = o.subject_id
     WHERE e.student_id = :sid AND e.offering_id = :oid AND o.teacher_id = :tid AND o.is_active = 1'
);
$off->execute([':sid' => $student_id, ':oid' => $offering_id, ':tid' => $tid]);
$offering = $off->fetch();
if (!$offering) {
    set_flash('error', 'Student is not enrolled in that offering, or it is not yours.');
    redirect('index.php');
}
$subject = $offering['subject_name'];

$errors = [];
if ($midRaw === '') {
    $errors[] = 'Midterm grade is required.';
} elseif (!is_numeric($midRaw)) {
    $errors[] = 'Only numbers are allowed for Midterm Grade.';
} elseif (!valid_exam_grade($midRaw)) {
    $errors[] = 'Midterm Grade must be between 50 - 100 only.';
}
if ($finRaw === '') {
    $errors[] = 'Final grade is required.';
} elseif (!is_numeric($finRaw)) {
    $errors[] = 'Only numbers are allowed for Final Grade.';
} elseif (!valid_exam_grade($finRaw)) {
    $errors[] = 'Final Grade must be between 50 - 100 only.';
}

if ($errors) {
    set_flash('error', implode(' ', $errors));
    redirect('class.php?open_grade=' . $student_id . '&offering_id=' . $offering_id);
}

$midterm = (float)$midRaw;
$final = (float)$finRaw;
[$qa, $aa, $semGrd, $remark, $point] = compute_grade([], [], $midterm, $final);

$existing = $pdo->prepare(
    'SELECT id FROM grades WHERE student_id = :sid AND (offering_id = :oid OR (offering_id IS NULL AND subject = :subj)) LIMIT 1'
);
$existing->execute([':sid' => $student_id, ':oid' => $offering_id, ':subj' => $subject]);
$gradeId = (int)$existing->fetchColumn();

if ($gradeId > 0) {
    $stmt = $pdo->prepare(
        'UPDATE grades SET teacher_id=:tid, offering_id=:oid, subject=:subj,
                quiz_scores=:qs, activity_scores=:as,
                midterm=:mid, midterm_max=:midm, final_exam=:fin, final_exam_max=:finm,
                quiz_avg=:qa, activity_avg=:aa, final_grade=:fg, grade_point=:gp, remark=:rem
         WHERE id=:id'
    );
    $stmt->execute([
        ':tid' => $tid, ':oid' => $offering_id, ':subj' => $subject,
        ':qs' => '[]', ':as' => '[]',
        ':mid' => $midterm, ':midm' => 100, ':fin' => $final, ':finm' => 100,
        ':qa' => 0, ':aa' => 0, ':fg' => $semGrd, ':gp' => $point, ':rem' => $remark,
        ':id' => $gradeId,
    ]);
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO grades
            (student_id, teacher_id, offering_id, subject, quiz_scores, activity_scores,
             midterm, midterm_max, final_exam, final_exam_max,
             quiz_avg, activity_avg, final_grade, grade_point, remark)
         VALUES (:sid,:tid,:oid,:subj,:qs,:as,:mid,:midm,:fin,:finm,:qa,:aa,:fg,:gp,:rem)'
    );
    $stmt->execute([
        ':sid' => $student_id, ':tid' => $tid, ':oid' => $offering_id, ':subj' => $subject,
        ':qs' => '[]', ':as' => '[]',
        ':mid' => $midterm, ':midm' => 100, ':fin' => $final, ':finm' => 100,
        ':qa' => 0, ':aa' => 0, ':fg' => $semGrd, ':gp' => $point, ':rem' => $remark,
    ]);
}

audit_log(
    'GRADE_UPDATED',
    'student#' . $student_id,
    $subject . ' → semestral ' . $semGrd . ' (' . $remark . '), point ' . $point
);
set_flash('ok', "Grade Information — Semestral Grade: {$semGrd} · Remarks: {$remark}.");
redirect('class.php?open_grade=' . $student_id . '&offering_id=' . $offering_id);
