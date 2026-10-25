<?php

namespace App\Support;

use Illuminate\Http\Response;

class ExactResponse
{
    /**
     * JSON with an explicit Content-Length. The ESP32 HTTP client cannot read a
     * chunked body, and nginx gzip strips Content-Length, so device routes also
     * send Cache-Control: no-transform (Cloudflare honors it; nginx still needs
     * gzip off — see deploy/nginx-swimshady.conf).
     */
    public static function json(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::raw($json, $status, 'application/json');
    }

    public static function text(string $body, int $status = 200): Response
    {
        return self::raw($body, $status, 'text/plain');
    }

    public static function raw(string $body, int $status, string $contentType): Response
    {
        return response($body, $status, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store, no-transform',
        ]);
    }
}
