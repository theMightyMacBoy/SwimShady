<?php

/**
 * Laravel's built-in PHP server front controller.
 *
 * RunCloud still looks for this file before it will mark a web app as Laravel.
 * Laravel 11 and later stopped shipping it. Nginx on RunCloud does not use it.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
