<?php
/**
 * includes/validation.php — input validators, username generator, grade math.
 */

// Grade computation (PE3): Semestral = (Midterm + Final) / 2
define('W_QUIZ', 0.0);
define('W_ACTIVITY', 0.0);
define('W_MIDTERM', 0.50);
define('W_FINAL', 0.50);
define('GRADE_INPUT_MIN', 50.0);
define('GRADE_INPUT_MAX', 100.0);
define('PASSING_GRADE', 74.5);

/** Midterm/Final: numeric and between 50–100 (PE3). */
function valid_exam_grade($value): bool
{
    if ($value === '' || $value === null || !is_numeric($value)) {
        return false;
    }
    $n = (float)$value;
    return $n >= GRADE_INPUT_MIN && $n <= GRADE_INPUT_MAX;
}

/** Names: letters and spaces only. */
function valid_name(string $s): bool
{
    $s = trim($s);
    return $s !== '' && (bool)preg_match('/^[A-Za-z ]+$/', $s);
}

/** Phone: exactly 11 digits, numbers only. */
function valid_phone(string $s): bool
{
    return (bool)preg_match('/^[0-9]{11}$/', $s);
}

/** Age: whole number only, sensible range. */
function valid_age(string $s): bool
{
    return ctype_digit($s) && (int)$s > 0 && (int)$s < 130;
}

/** Email: valid format AND must be a gmail.com account. */
function valid_email(string $s): bool
{
    return (bool)filter_var($s, FILTER_VALIDATE_EMAIL)
        && (bool)preg_match('/@gmail\.com$/i', trim($s));
}

/** School ID / password format e.g. 2026-00200. */
function valid_school_id(string $s): bool
{
    return (bool)preg_match('/^\d{4}-\d{5}$/', $s);
}

/**
 * Build a username like "B_Delossantos" from first + last name.
 * Ensures uniqueness by appending a number if needed.
 */
function generate_username(PDO $pdo, string $first, string $last): string
{
    $initial = strtoupper(substr(trim($first), 0, 1));
    $lastClean = ucfirst(strtolower(preg_replace('/\s+/', '', $last)));
    $base = $initial . '_' . $lastClean;

    $username = $base;
    $n = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
    while (true) {
        $stmt->execute([':u' => $username]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $username;
        }
        $n++;
        $username = $base . $n;
    }
}

/** Average of a numeric list (0 if empty). */
function avg_scores(array $scores): float
{
    $scores = array_values(array_filter($scores, 'is_numeric'));
    if (!$scores) return 0.0;
    return array_sum(array_map('floatval', $scores)) / count($scores);
}

/**
 * Compute semestral grade (PE3): (Midterm + Final) / 2.
 * Remarks: Passed if >= 74.5, else Failed.
 * Returns [quiz_avg, activity_avg, semestral_percent, remark, grade_point].
 */
function compute_grade(array $quizzes, array $activities, float $midterm, float $final): array
{
    $qa = 0.0;
    $aa = 0.0;
    $semGrd = round(($midterm + $final) / 2, 2);
    $remark = $semGrd >= PASSING_GRADE ? 'Passed' : 'Failed';
    if (function_exists('map_percent_to_scale')) {
        [$point] = map_percent_to_scale($semGrd);
    } else {
        $point = $semGrd >= PASSING_GRADE ? 3.0 : 5.0;
    }
    if ($remark === 'Failed') {
        $point = 5.0;
    }
    return [$qa, $aa, $semGrd, $remark, $point];
}
