<?php
/**
 * includes/qr.php — QR image URL for school ID badges (no local deps).
 */

function school_id_qr_url(string $payload, int $size = 120): string
{
    $payload = trim($payload);
    if ($payload === '' || $payload === '—') {
        $payload = 'SECURE-SIMS';
    }
    $size = max(64, min(240, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
        'size' => $size . 'x' . $size,
        'data' => $payload,
        'margin' => 1,
        'ecc' => 'M',
    ]);
}
