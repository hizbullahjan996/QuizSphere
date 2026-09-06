<?php
/**
 * QuizSphere - Shared UI fragment helpers.
 *
 * Phase 1: lightweight helpers used across static frontend pages.
 */

declare(strict_types=1);

/**
 * Build a fully-qualified URL (root or sub-path aware) for an asset/link.
 *
 * @param string $path Path beginning with '/'.
 * @return string
 */
function url(string $path): string
{
    $base = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
    return $base . '/' . ltrim($path, '/');
}

/**
 * Return the current page's basename for nav highlighting.
 */
function active_page(): string
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    // Files inside /pages share the folder; prefix with folder name.
    $dir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return $dir === 'pages' ? $dir : $script;
}

/**
 * Escape output for safe HTML rendering.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
