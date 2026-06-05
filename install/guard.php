<?php
/**
 * Block install endpoints after successful installation.
 */
function usk_install_guard()
{
    if (file_exists(__DIR__ . '/unlimitsky.install')) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
}
