<?php
/**
 * MySQL connection using PDO + prepared statements.
 *
 * Security theme note:
 *   - We connect with a least-privilege mindset and ALWAYS use prepared
 *     statements (even for illustrative/seed reads) to model good practice.
 *   - If the DB is unavailable, callers fall back to built-in sample data so
 *     the demo never blank-screens.
 *
 * Returns a PDO instance on success, or null on failure.
 */

function db_connect(): ?PDO
{
    // XAMPP defaults. Adjust if your local setup differs.
    $host    = '127.0.0.1';
    $port    = '3306';
    $dbname  = 'secure_sims';
    $user    = 'root';
    $pass    = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (Throwable $e) {
        // Swallow the error on purpose: the app degrades gracefully to
        // built-in fallback data. Log server-side for debugging only.
        error_log('[secure_sims] DB connection failed: ' . $e->getMessage());
        return null;
    }
}
