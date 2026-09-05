<?php
/**
 * includes/grading.php — configurable grading weights + rating scale.
 * Default scale matches the common 1.0–5.0 Philippine collegiate chart.
 */

function default_grading_scale(): array
{
    return [
        ['grade_point' => 1.00, 'min_percent' => 98.00, 'max_percent' => 100.00, 'description' => 'Excellent'],
        ['grade_point' => 1.25, 'min_percent' => 95.00, 'max_percent' => 97.00,  'description' => 'Outstanding'],
        ['grade_point' => 1.50, 'min_percent' => 92.00, 'max_percent' => 94.00,  'description' => 'Very Good'],
        ['grade_point' => 1.75, 'min_percent' => 89.00, 'max_percent' => 91.00,  'description' => 'Above Average'],
        ['grade_point' => 2.00, 'min_percent' => 86.00, 'max_percent' => 88.00,  'description' => 'Good'],
        ['grade_point' => 2.25, 'min_percent' => 83.00, 'max_percent' => 85.00,  'description' => 'Fairly Good'],
        ['grade_point' => 2.50, 'min_percent' => 80.00, 'max_percent' => 82.00,  'description' => 'Satisfactory'],
        ['grade_point' => 2.75, 'min_percent' => 76.00, 'max_percent' => 79.00,  'description' => 'Fair'],
        ['grade_point' => 3.00, 'min_percent' => 74.50, 'max_percent' => 75.99, 'description' => 'Passing'],
        ['grade_point' => 5.00, 'min_percent' => 0.00,  'max_percent' => 74.49, 'description' => 'Failure'],
    ];
}

function ensure_grading_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `grading_scale` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `grade_point` DECIMAL(4,2) NOT NULL,
            `min_percent` DECIMAL(5,2) NOT NULL,
            `max_percent` DECIMAL(5,2) NOT NULL,
            `description` VARCHAR(40) NOT NULL DEFAULT "",
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Widen remark for descriptions like "Satisfactory".
    try {
        $pdo->exec('ALTER TABLE grades MODIFY `remark` VARCHAR(40) NOT NULL DEFAULT "N/A"');
    } catch (Throwable $e) {
        // ignore if already migrated / table missing during fresh import race
    }

    $col = $pdo->query("SHOW COLUMNS FROM grades LIKE 'grade_point'")->fetch();
    if (!$col) {
        try {
            $pdo->exec('ALTER TABLE grades ADD COLUMN `grade_point` DECIMAL(4,2) NULL DEFAULT NULL AFTER `final_grade`');
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Score / total storage for midterm & final (e.g. 32 out of 50).
    foreach (['midterm_max', 'final_exam_max'] as $col) {
        $exists = $pdo->query("SHOW COLUMNS FROM grades LIKE '{$col}'")->fetch();
        if (!$exists) {
            try {
                $pdo->exec(
                    "ALTER TABLE grades ADD COLUMN `{$col}` DECIMAL(7,2) NOT NULL DEFAULT 100.00
                     AFTER `" . ($col === 'midterm_max' ? 'midterm' : 'final_exam') . '`'
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
    try {
        $pdo->exec('ALTER TABLE grades MODIFY `midterm` DECIMAL(7,2) NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE grades MODIFY `final_exam` DECIMAL(7,2) NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // ignore
    }

    $defaults = [
        'weight_quiz'     => '0',
        'weight_activity' => '0',
        'weight_midterm'  => '50',
        'weight_final'    => '50',
        'passing_point'   => '3.00',
    ];
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (:k, :v)'
    );
    foreach ($defaults as $k => $v) {
        $ins->execute([':k' => $k, ':v' => $v]);
    }

    // Migrate to Midterm + Final only (drop quiz/activity from the formula).
    if ((float)app_setting('weight_quiz', '0') > 0 || (float)app_setting('weight_activity', '0') > 0) {
        set_app_setting('weight_quiz', '0');
        set_app_setting('weight_activity', '0');
        set_app_setting('weight_midterm', '50');
        set_app_setting('weight_final', '50');
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM grading_scale')->fetchColumn() === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO grading_scale (grade_point, min_percent, max_percent, description, sort_order)
             VALUES (:gp, :min, :max, :d, :s)'
        );
        foreach (default_grading_scale() as $i => $row) {
            $stmt->execute([
                ':gp'  => $row['grade_point'],
                ':min' => $row['min_percent'],
                ':max' => $row['max_percent'],
                ':d'   => $row['description'],
                ':s'   => $i + 1,
            ]);
        }
    } else {
        // Fill blank descriptions on existing default bands.
        $upd = $pdo->prepare(
            'UPDATE grading_scale SET description = :d
             WHERE grade_point = :gp AND (description IS NULL OR description = "")'
        );
        foreach (default_grading_scale() as $row) {
            if ($row['description'] === '') {
                continue;
            }
            $upd->execute([':d' => $row['description'], ':gp' => $row['grade_point']]);
        }

        // Align pass/fail bands with PE3 (Passed at semestral >= 74.5).
        try {
            $pdo->exec(
                'UPDATE grading_scale SET min_percent = 74.50, max_percent = 75.99, description = "Passing"
                 WHERE grade_point = 3.00 AND min_percent IN (75.00, 74.50)'
            );
            $pdo->exec(
                'UPDATE grading_scale SET max_percent = 74.49, description = "Failure"
                 WHERE grade_point = 5.00 AND max_percent IN (74.00, 74.49)'
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    $ready = true;
}

function grading_weights(): array
{
    ensure_grading_schema();
    $q = (float)app_setting('weight_quiz', '0');
    $a = (float)app_setting('weight_activity', '0');
    $m = (float)app_setting('weight_midterm', '50');
    $f = (float)app_setting('weight_final', '50');
    // Teachers grade with Midterm + Final only.
    $q = 0.0;
    $a = 0.0;
    $sum = $m + $f;
    if ($sum <= 0) {
        return [
            'quiz' => 0, 'activity' => 0, 'midterm' => 0.50, 'final' => 0.50,
            'quiz_pct' => 0, 'activity_pct' => 0, 'midterm_pct' => 50, 'final_pct' => 50,
        ];
    }
    return [
        'quiz'     => $q / $sum,
        'activity' => $a / $sum,
        'midterm'  => $m / $sum,
        'final'    => $f / $sum,
        'quiz_pct' => $q,
        'activity_pct' => $a,
        'midterm_pct' => $m,
        'final_pct' => $f,
    ];
}

function grading_scale_rows(): array
{
    ensure_grading_schema();
    return db()->query(
        'SELECT id, grade_point, min_percent, max_percent, description, sort_order
         FROM grading_scale ORDER BY sort_order, grade_point'
    )->fetchAll();
}

function passing_grade_point(): float
{
    ensure_grading_schema();
    return (float)app_setting('passing_point', '3.00');
}

/**
 * Map a percentage (0–100) to [grade_point, description].
 * Prefer exact min–max band; if gaps, use nearest lower min_percent.
 */
function map_percent_to_scale(float $percent): array
{
    $percent = max(0, min(100, $percent));
    $rows = grading_scale_rows();
    foreach ($rows as $row) {
        $min = (float)$row['min_percent'];
        $max = (float)$row['max_percent'];
        if ($percent + 0.00001 >= $min && $percent - 0.00001 <= $max) {
            $desc = trim((string)$row['description']);
            if ($desc === '') {
                $desc = grade_point_is_passing((float)$row['grade_point']) ? 'Passed' : 'Failed';
            }
            return [(float)$row['grade_point'], $desc];
        }
    }

    // Gap fallback: highest min_percent that is still <= score
    usort($rows, static fn($a, $b) => (float)$b['min_percent'] <=> (float)$a['min_percent']);
    foreach ($rows as $row) {
        if ($percent + 0.00001 >= (float)$row['min_percent']) {
            $desc = trim((string)$row['description']);
            if ($desc === '') {
                $desc = grade_point_is_passing((float)$row['grade_point']) ? 'Passed' : 'Failed';
            }
            return [(float)$row['grade_point'], $desc];
        }
    }

    return [5.00, 'Failure'];
}

function grade_point_is_passing(float $point): bool
{
    return $point > 0 && $point <= passing_grade_point() + 0.00001;
}

function grade_tag_class(?string $remark, $gradePoint = null): string
{
    if ($gradePoint !== null && $gradePoint !== '') {
        return grade_point_is_passing((float)$gradePoint) ? 'tag--pass' : 'tag--fail';
    }
    $r = strtolower((string)$remark);
    if (in_array($r, ['failure', 'failed', '5.0', '5.00'], true)) {
        return 'tag--fail';
    }
    if ($r === 'passed' || $r === 'passing' || $r === 'excellent' || $r === 'very good' || $r === 'good' || $r === 'satisfactory') {
        return 'tag--pass';
    }
    return 'tag--pass';
}

function format_grade_cell($percent, $gradePoint, ?string $remark = null): string
{
    $pct = $percent === null || $percent === '' ? '—' : number_format((float)$percent, 2);
    if ($gradePoint === null || $gradePoint === '') {
        return '<strong>' . e($pct) . '</strong>';
    }
    return '<strong>' . e(number_format((float)$gradePoint, 2)) . '</strong>'
        . ' <span class="grade-pct">(' . e($pct) . '%)</span>';
}

/** Trim trailing zeros from a numeric display value. */
function format_score_number($n): string
{
    $s = number_format((float)$n, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

/**
 * Normalize one score entry to ['score'=>float,'max'=>float].
 * Accepts legacy plain numbers (treated as score out of 100).
 */
function normalize_score_entry($item): ?array
{
    if (is_array($item) && isset($item['score'], $item['max'])) {
        $score = (float)$item['score'];
        $max = (float)$item['max'];
        if ($max <= 0) {
            return null;
        }
        return ['score' => $score, 'max' => $max];
    }
    if (is_numeric($item)) {
        return ['score' => (float)$item, 'max' => 100.0];
    }
    return null;
}

/** Decode quiz/activity JSON into a list of score/max pairs. */
function decode_score_entries(?string $json): array
{
    $raw = json_decode($json ?? '[]', true);
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $item) {
        $e = normalize_score_entry($item);
        if ($e !== null) {
            $out[] = $e;
        }
    }
    return $out;
}

function encode_score_entries(array $entries): string
{
    $clean = [];
    foreach ($entries as $e) {
        $n = normalize_score_entry($e);
        if ($n !== null) {
            $clean[] = ['score' => $n['score'], 'max' => $n['max']];
        }
    }
    return json_encode($clean);
}

/** Percent from score/max (e.g. 32/50 → 64). */
function score_entry_percent(array $entry): float
{
    $n = normalize_score_entry($entry);
    if ($n === null || $n['max'] <= 0) {
        return 0.0;
    }
    return round(100.0 * $n['score'] / $n['max'], 2);
}

/** Display like 32/50. */
function format_score_entry(array $entry): string
{
    $n = normalize_score_entry($entry);
    if ($n === null) {
        return '—';
    }
    return format_score_number($n['score']) . '/' . format_score_number($n['max']);
}

function format_score_entries_list(array $entries): string
{
    if (!$entries) {
        return '—';
    }
    return implode(', ', array_map('format_score_entry', $entries));
}

function format_exam_score($score, $max = null): string
{
    $m = ($max === null || $max === '') ? 100.0 : (float)$max;
    return format_score_entry(['score' => (float)$score, 'max' => $m]);
}

/** Percent list from stored quiz/activity JSON (supports legacy score/max objects). */
function grades_percent_list(?string $json): array
{
    return array_map('score_entry_percent', decode_score_entries($json));
}

/** Midterm/final as a 0–100 percentage (converts legacy score/total if needed). */
function exam_as_percent($score, $max = null): float
{
    $m = ($max === null || $max === '') ? 100.0 : (float)$max;
    if ($m <= 0) {
        return 0.0;
    }
    return round(100.0 * (float)$score / $m, 2);
}

/** Display percents like 90, 85, 88. */
function format_percent_list(array $percents): string
{
    if (!$percents) {
        return '—';
    }
    return implode(', ', array_map('format_score_number', $percents));
}

/**
 * Build score/max pairs from parallel POST arrays (score[] + max[]).
 * Skips blank rows; returns [entries, errorMessage|null].
 */
function parse_score_pairs_from_post(array $scores, array $maxes): array
{
    $entries = [];
    $n = max(count($scores), count($maxes));
    for ($i = 0; $i < $n; $i++) {
        $s = trim((string)($scores[$i] ?? ''));
        $m = trim((string)($maxes[$i] ?? ''));
        if ($s === '' && $m === '') {
            continue;
        }
        if ($s === '' || $m === '' || !is_numeric($s) || !is_numeric($m)) {
            return [[], 'Each score needs both points earned and total (e.g. 32 and 50).'];
        }
        $score = (float)$s;
        $max = (float)$m;
        if ($score < 0 || $max <= 0) {
            return [[], 'Totals must be greater than 0, and scores cannot be negative.'];
        }
        if ($score > $max) {
            return [[], 'A score cannot be higher than its total (e.g. 32/50).'];
        }
        $entries[] = ['score' => $score, 'max' => $max];
    }
    return [$entries, null];
}
