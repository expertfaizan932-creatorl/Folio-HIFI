<?php
/**
 * Router for PHP's built-in web server, used only by the HRM end-to-end test.
 *
 * The built-in server decides from the file extension whether a request is for a
 * file, so a path like /hrm/1/doc/cnic_copy/x.pdf is answered with its own 404
 * and never reaches index.php. Apache's mod_rewrite rules in .htaccess do not
 * behave that way, so the test would be measuring the wrong thing. This sends
 * everything that is not a real file to index.php, exactly as production does.
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/* Never let the built-in server hand out anything under data/. index.php blocks
   this too; the guard is repeated here because this file runs first. */
if (strpos($path, '/data/') === 0 || $path === '/data') {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

$root = __DIR__;
$real = @realpath($root . '/' . ltrim($path, '/'));
if ($path !== '/' && $real !== false && is_file($real) && strpos($real, realpath($root)) === 0) {
    return false;   /* a genuine static file: let the server serve it */
}

require $root . '/index.php';
return true;