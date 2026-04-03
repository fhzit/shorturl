<?php

declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/data/shorturl.sqlite',
    'admin_username' => getenv('SHORTURL_ADMIN_USERNAME') ?: 'admin',
    'admin_password' => getenv('SHORTURL_ADMIN_PASSWORD') ?: 'admin123',
    'reserved_codes' => ['admin', 'health', 'assets'],
];
