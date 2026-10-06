<?php

declare(strict_types=1);

// Load composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load CI4 global helper functions so PHPStan can discover symbols
$helpers = [
    __DIR__ . '/../vendor/codeigniter4/framework/system/Common.php',
    __DIR__ . '/../vendor/codeigniter4/framework/system/Helpers/url_helper.php',
];

foreach ($helpers as $helper) {
    if (file_exists($helper)) {
        require_once $helper;
    }
}

// Stub functions not provided by CI4 but used by this package
if (!function_exists('view_exists')) {
    function view_exists(string $view): bool
    {
        return false;
    }
}
