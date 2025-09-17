<?php
declare(strict_types=1);

namespace App\Support;

class Csrf
{
    public static function ensureToken(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function getToken(): string
    {
        self::ensureToken();
        return (string)($_SESSION['csrf_token'] ?? '');
    }

    public static function validate(?string $token): bool
    {
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        return $sessionToken !== '' && $token !== null && hash_equals($sessionToken, (string)$token);
    }
}

