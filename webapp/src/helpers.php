<?php
declare(strict_types=1);

/** Read the loaded config (whole array, or a top-level section). */
function config(?string $key = null)
{
    global $config;
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? null;
}

/** HTML-escape for output. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Redirect and stop. */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** Emit JSON and stop. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
