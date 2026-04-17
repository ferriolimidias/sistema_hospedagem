<?php
declare(strict_types=1);

function getOrCreateInternalApiKey(PDO $pdo): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'internalApiKey' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    $existing = trim((string)($row['setting_value'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    $generated = bin2hex(random_bytes(24));
    $upsert = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES ('internalApiKey', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $upsert->execute([$generated]);
    return $generated;
}

function getContractAccessExpiryTimestamp(string $checkoutDate): int
{
    $baseTs = strtotime($checkoutDate . ' 00:00:00');
    if ($baseTs === false) {
        return 0;
    }
    return $baseTs + 86400; // checkout_date + 24 horas
}

function buildContractAccessToken(
    int $reservationId,
    string $filename,
    string $email,
    string $checkoutDate,
    string $secret
): string {
    $expiryTs = getContractAccessExpiryTimestamp($checkoutDate);
    $payload = $reservationId . '|' . strtolower(trim($email)) . '|' . $filename . '|' . $expiryTs;
    return hash_hmac('sha256', $payload, $secret);
}

function validateContractAccessToken(
    int $reservationId,
    string $filename,
    string $email,
    string $checkoutDate,
    string $token,
    string $secret
): bool {
    $expiryTs = getContractAccessExpiryTimestamp($checkoutDate);
    if ($expiryTs <= 0 || time() > $expiryTs) {
        return false;
    }

    if ($token === '') return false;
    $expected = buildContractAccessToken($reservationId, $filename, $email, $checkoutDate, $secret);
    return hash_equals($expected, $token);
}

