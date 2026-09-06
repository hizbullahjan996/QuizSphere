<?php
/**
 * QuizSphere - Simple file-based rate limiter
 * -------------------------------------------------------------
 * Limits how often a given actor (user id or IP) may trigger an action
 * such as an AI generation call, to prevent abuse and runaway cost.
 *
 * Values are derived from configuration with safe defaults:
 *   AI_RATE_LIMIT_MAX   (default 10  per window)
 *   AI_RATE_LIMIT_WINDOW(default 3600 seconds)
 * These can be defined in config/env.php.
 */

declare(strict_types=1);

class RateLimiter
{
    public const DEFAULT_MAX = 10;
    public const DEFAULT_WINDOW = 3600;

    private static ?string $dir = null;

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = __DIR__ . '/../storage/rate_limits';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0775, true);
            }
        }
        return self::$dir;
    }

    private static function maxAllowed(): int
    {
        $max = defined('AI_RATE_LIMIT_MAX') ? (int) AI_RATE_LIMIT_MAX : self::DEFAULT_MAX;
        return $max > 0 ? $max : self::DEFAULT_MAX;
    }

    private static function windowSeconds(): int
    {
        $w = defined('AI_RATE_LIMIT_WINDOW') ? (int) AI_RATE_LIMIT_WINDOW : self::DEFAULT_WINDOW;
        return $w > 0 ? $w : self::DEFAULT_WINDOW;
    }

    private static function actorKey(string $actor): string
    {
        // Hash so the raw identifier is not stored in the filename.
        return hash('sha256', $actor);
    }

    /**
     * Register a hit for an actor. Returns true if still within the limit.
     */
    public static function hit(string $actor): bool
    {
        $key = self::actorKey($actor);
        $file = self::dir() . '/' . $key . '.json';
        $now = time();
        $window = self::windowSeconds();

        $record = ['hits' => [], 'hold_until' => 0];
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $record = $data;
            }
        }

        // Prune expired hits.
        $record['hits'] = array_values(array_filter($record['hits'], fn ($t) => $now - (int) $t < $window));

        if (count($record['hits']) >= self::maxAllowed()) {
            self::save($file, $record);
            return false;
        }

        $record['hits'][] = $now;
        self::save($file, $record);
        return true;
    }

    /**
     * Set a temporary "hold" (cooldown) after repeated failures, preventing
     * immediate retry storms. Returns true when the actor may proceed.
     */
    public static function hold(string $actor, int $cooldownSeconds = 60): bool
    {
        $key = self::actorKey($actor);
        $file = self::dir() . '/' . $key . '.json';
        $now = time();

        $record = ['hits' => [], 'hold_until' => 0];
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $record = $data;
            }
        }

        if ($record['hold_until'] > $now) {
            return false;
        }
        $record['hold_until'] = $now + $cooldownSeconds;
        self::save($file, $record);
        return true;
    }

    private static function save(string $file, array $record): void
    {
        @file_put_contents($file, json_encode($record), LOCK_EX);
    }

    /**
     * Build a canonical actor string from user id and IP address.
     */
    public static function actor(?string $userId, ?string $ip): string
    {
        return ($userId ?? 'anon') . '|' . ($ip ?? 'unknown');
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($ip) && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        if (!$ip) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
        return substr((string) $ip, 0, 64);
    }
}
