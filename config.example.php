<?php
if (!defined('PASTEHUB_ENTRY')) {
    http_response_code(404);
    exit;
}

// Copy this file to config.php and replace the value with a long random secret.
define('PASTEHUB_UPLOAD_PASSWORD', 'CHANGE_ME_TO_A_LONG_RANDOM_PASSWORD');

