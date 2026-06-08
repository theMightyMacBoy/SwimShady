<?php
declare(strict_types=1);

/**
 * Call the Azure API. Server-to-server cURL, so any transfer framing is fine.
 * Returns ['status' => int, 'data' => mixed, 'error' => ?string].
 * status 0 means the API was unreachable.
 */
function api_call(string $method, string $path, ?array $body = null, ?string $bearer = null): array
{
    $url = rtrim((string) config('api_base'), '/') . $path;

    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($bearer !== null && $bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw   = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0) {
        return ['status' => 0, 'data' => null, 'error' => 'API unreachable'];
    }
    $data = ($raw === '' || $raw === false) ? null : json_decode($raw, true);
    return ['status' => $code, 'data' => $data, 'error' => null];
}

function api_get(string $path, ?string $bearer = null): array
{
    return api_call('GET', $path, null, $bearer);
}

function api_post(string $path, ?array $body, ?string $bearer = null): array
{
    return api_call('POST', $path, $body, $bearer);
}

function api_put(string $path, ?array $body, ?string $bearer = null): array
{
    return api_call('PUT', $path, $body, $bearer);
}

function api_delete(string $path, ?string $bearer = null): array
{
    return api_call('DELETE', $path, null, $bearer);
}

/** Multipart upload (e.g. firmware .bin) to the API. $fields are extra form fields. */
function api_upload(string $path, array $fields, string $fileField, string $tmpPath, string $filename, ?string $bearer = null): array
{
    $url = rtrim((string) config('api_base'), '/') . $path;
    $headers = ['Accept: application/json'];
    if ($bearer !== null && $bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }

    $post = $fields;
    $post[$fileField] = new CURLFile($tmpPath, 'application/octet-stream', $filename);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers, // let cURL set the multipart Content-Type
        CURLOPT_POSTFIELDS     => $post,    // array → multipart/form-data
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw   = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0) {
        return ['status' => 0, 'data' => null, 'error' => 'API unreachable'];
    }
    $data = ($raw === '' || $raw === false) ? null : json_decode($raw, true);
    return ['status' => $code, 'data' => $data, 'error' => null];
}
