<?php
/**
 * Login guard helper functions for tracking failed login attempts and locks.
 */

declare(strict_types=1);

const LOGIN_GUARD_TABLE       = 'LoginFailures';
const LOGIN_FAILURE_TTL       = 3600; // seconds to retain failures (1 hour)
const LOGIN_CAPTCHA_THRESHOLD = 5;
const LOGIN_LOCK_THRESHOLD    = 12;
const LOGIN_LOCK_SECONDS      = 900; // 15 minutes

function login_guard_bootstrap(PDO $pdo): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS " . LOGIN_GUARD_TABLE . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(191) NOT NULL UNIQUE,
            failures INT NOT NULL DEFAULT 0,
            last_failure INT NOT NULL,
            locked_until INT NOT NULL DEFAULT 0,
            INDEX idx_last_failure (last_failure)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $cutoff = time() - LOGIN_FAILURE_TTL;
    $stmt   = $pdo->prepare("DELETE FROM " . LOGIN_GUARD_TABLE . " WHERE last_failure < ?");
    $stmt->execute([$cutoff]);

    $bootstrapped = true;
}

function login_guard_identifier_ip(string $ip): string
{
    return 'ip:' . strtolower(trim($ip));
}

function login_guard_identifier_user(?string $username): ?string
{
    if ($username === null || $username === '') {
        return null;
    }
    return 'user:' . mb_strtolower(trim($username), 'UTF-8');
}

function login_guard_fetch(PDO $pdo, string $identifier): array
{
    login_guard_bootstrap($pdo);

    $stmt = $pdo->prepare(
        "SELECT failures, last_failure, locked_until FROM " . LOGIN_GUARD_TABLE . " WHERE identifier = ? LIMIT 1"
    );
    $stmt->execute([$identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['failures' => 0, 'locked_until' => 0];
    }

    $lastFailure = (int)($row['last_failure'] ?? 0);
    if ($lastFailure < time() - LOGIN_FAILURE_TTL) {
        login_guard_clear($pdo, $identifier);
        return ['failures' => 0, 'locked_until' => 0];
    }

    $lockedUntil = (int)($row['locked_until'] ?? 0);
    if ($lockedUntil <= time()) {
        $lockedUntil = 0;
    }

    return [
        'failures' => (int)($row['failures'] ?? 0),
        'locked_until' => $lockedUntil,
    ];
}

function login_guard_record_failure(PDO $pdo, string $identifier): array
{
    login_guard_bootstrap($pdo);
    $now         = time();
    $state       = login_guard_fetch($pdo, $identifier);
    $newFailures = $state['failures'] + 1;
    $lockedUntil = $state['locked_until'];

    if (LOGIN_LOCK_THRESHOLD > 0 && $newFailures >= LOGIN_LOCK_THRESHOLD) {
        $lockedUntil = max($lockedUntil, $now + LOGIN_LOCK_SECONDS);
    }

    if ($state['failures'] === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO " . LOGIN_GUARD_TABLE . " (identifier, failures, last_failure, locked_until)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$identifier, $newFailures, $now, $lockedUntil]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE " . LOGIN_GUARD_TABLE . "
             SET failures = ?, last_failure = ?, locked_until = ?
             WHERE identifier = ?"
        );
        $stmt->execute([$newFailures, $now, $lockedUntil, $identifier]);
    }

    return ['failures' => $newFailures, 'locked_until' => $lockedUntil];
}

function login_guard_clear(PDO $pdo, string $identifier): void
{
    login_guard_bootstrap($pdo);
    $stmt = $pdo->prepare("DELETE FROM " . LOGIN_GUARD_TABLE . " WHERE identifier = ?");
    $stmt->execute([$identifier]);
}

function login_guard_collect(PDO $pdo, array $identifiers): array
{
    $maxFailures    = 0;
    $maxLockedUntil = 0;
    foreach ($identifiers as $identifier) {
        $identifier = trim($identifier);
        if ($identifier === '') {
            continue;
        }
        $state          = login_guard_fetch($pdo, $identifier);
        $maxFailures    = max($maxFailures, $state['failures']);
        $maxLockedUntil = max($maxLockedUntil, $state['locked_until']);
    }

    return [
        'max_failures' => $maxFailures,
        'locked_until' => $maxLockedUntil,
    ];
}

function login_guard_record_failure_for(PDO $pdo, array $identifiers): void
{
    foreach ($identifiers as $identifier) {
        $identifier = trim($identifier);
        if ($identifier === '') {
            continue;
        }
        login_guard_record_failure($pdo, $identifier);
    }
}

function login_guard_reset_for(PDO $pdo, array $identifiers): void
{
    foreach ($identifiers as $identifier) {
        $identifier = trim($identifier);
        if ($identifier === '') {
            continue;
        }
        login_guard_clear($pdo, $identifier);
    }
}

function login_guard_get_identifiers(?string $username, string $ip): array
{
    $identifiers = [];
    $userId      = login_guard_identifier_user($username);
    if ($userId) {
        $identifiers[] = $userId;
    }
    if ($ip !== '') {
        $identifiers[] = login_guard_identifier_ip($ip);
    }
    return array_values(array_unique($identifiers));
}

function login_guard_seconds_until_unlock(int $lockedUntil): int
{
    $now = time();
    if ($lockedUntil <= $now) {
        return 0;
    }
    return $lockedUntil - $now;
}