<?php
/**
 * QuizSphere - Application logger
 * -------------------------------------------------------------
 * Writes technical errors to a rotating log file under storage/logs.
 * Secrets (API keys, tokens, full password values) must NEVER be logged.
 * Only sanitized technical details are recorded for debugging.
 */

declare(strict_types=1);

class AppLogger
{
    private static ?string $dir = null;

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = __DIR__ . '/../storage/logs';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0775, true);
            }
        }
        return self::$dir;
    }

    /**
     * Write a sanitized error line to the log.
     *
     * @param string   $message  Human-readable safe message.
     * @param array    $context  Safe technical context (already stripped of secrets).
     * @param string|null $channel Optional log file name (default: app).
     */
    public static function error(string $message, array $context = [], ?string $channel = 'app'): void
    {
        self::write('ERROR', $message, $context, $channel);
    }

    public static function warning(string $message, array $context = [], ?string $channel = 'app'): void
    {
        self::write('WARN', $message, $context, $channel);
    }

    public static function info(string $message, array $context = [], ?string $channel = 'app'): void
    {
        self::write('INFO', $message, $context, $channel);
    }

    /**
     * Remove anything that looks like a key/token from a string.
     * Use this before logging any provider/network payloads.
     */
    public static function sanitize(string $text): string
    {
        $text = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '[REDACTED_KEY]', $text);
        $text = preg_replace('/gsk_[0-9A-Za-z_\-]{20,}/i', '[REDACTED_KEY]', $text);
        $text = preg_replace('/eyJ[A-Za-z0-9_\-\.]{20,}/', '[REDACTED_TOKEN]', $text);
        return $text;
    }

    private static function write(string $level, string $message, array $context, string $channel): void
    {
        try {
            $line = sprintf(
                "[%s] %s %s %s\n",
                date('Y-m-d H:i:s'),
                $level,
                $message,
                $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
            );
            $file = self::dir() . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $channel) . '.log';
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Never let logging break the request; silent failure.
        }
    }
}
