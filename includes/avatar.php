<?php
/**
 * includes/avatar.php — profile picture (pfp) helpers.
 */

function ensure_user_avatar_column(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `avatar` VARCHAR(120) NULL DEFAULT NULL");
    }
    $ready = true;
}

/**
 * Visible school ID number (also used as login password). Separate from password_hash.
 */
function ensure_user_school_id_column(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'school_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `school_id` VARCHAR(20) NULL DEFAULT NULL AFTER `username`");
    }
    $ready = true;
}

/**
 * Recover school_id from password for seeded 2026-xxxxx accounts.
 * Call from a one-off script only — bcrypt probing is slow.
 */
function backfill_school_ids_from_passwords(?PDO $pdo = null, int $from = 100, int $to = 300): int
{
    $pdo = $pdo ?? db();
    ensure_user_school_id_column($pdo);
    $missing = $pdo->query(
        "SELECT id, password_hash FROM users
         WHERE role IN ('student','teacher') AND (school_id IS NULL OR school_id = '')"
    )->fetchAll();
    if (!$missing) {
        return 0;
    }
    $upd = $pdo->prepare('UPDATE users SET school_id = :s WHERE id = :id');
    $filled = 0;
    foreach ($missing as $row) {
        for ($n = $from; $n <= $to; $n++) {
            $candidate = '2026-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
            if (password_verify($candidate, $row['password_hash'])) {
                $upd->execute([':s' => $candidate, ':id' => (int)$row['id']]);
                $filled++;
                break;
            }
        }
    }
    return $filled;
}

function avatar_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Public URL relative to a role subfolder (admin/teacher/student). */
function avatar_url(?string $filename, string $prefix = '../'): string
{
    if ($filename === null || $filename === '') {
        return '';
    }
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
        return '';
    }
    return $prefix . 'uploads/avatars/' . rawurlencode($filename);
}

function avatar_img_tag(?string $filename, string $alt, string $class = 'avatar', string $prefix = '../'): string
{
    $url = avatar_url($filename, $prefix);
    if ($url === '') {
        $initial = strtoupper(substr(trim($alt) !== '' ? $alt : '?', 0, 1));
        return '<span class="' . e($class) . ' avatar--placeholder" aria-hidden="true">' . e($initial) . '</span>';
    }
    return '<img class="' . e($class) . '" src="' . e($url) . '" alt="' . e($alt) . '" data-testid="user-avatar">';
}

/**
 * Validate and store an uploaded avatar. Returns [filename|null, error|null].
 */
function store_avatar_upload(array $file, int $userId, ?string $oldFilename = null): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'Please choose an image file.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed. Please try again.'];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 2 * 1024 * 1024) {
        return [null, 'Image must be 2 MB or smaller.'];
    }

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [null, 'Invalid upload.'];
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        return [null, 'File must be a valid image (JPG, PNG, or WebP).'];
    }
    $mime = $info['mime'] ?? '';
    $map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($map[$mime])) {
        return [null, 'Only JPG, PNG, or WebP images are allowed.'];
    }

    $filename = 'u' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    $dest = avatar_dir() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return [null, 'Could not save the image.'];
    }

    if ($oldFilename && preg_match('/^[a-zA-Z0-9._-]+$/', $oldFilename)) {
        $oldPath = avatar_dir() . DIRECTORY_SEPARATOR . $oldFilename;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    return [$filename, null];
}

function delete_avatar_file(?string $filename): void
{
    if (!$filename || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
        return;
    }
    $path = avatar_dir() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}
