<?php

declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/data/shorturl.sqlite',
    'admin_username' => getenv('SHORTURL_ADMIN_USERNAME') ?: 'admin',
    'admin_password' => getenv('SHORTURL_ADMIN_PASSWORD') ?: 'admin123',
    'turnstile_site_key' => getenv('SHORTURL_TURNSTILE_SITE_KEY') ?: '',
    'turnstile_secret_key' => getenv('SHORTURL_TURNSTILE_SECRET_KEY') ?: '',
    'reserved_codes' => ['admin', 'health', 'assets'],
    'login_max_attempts' => (int) (getenv('SHORTURL_LOGIN_MAX_ATTEMPTS') ?: 8),
    'login_window_seconds' => (int) (getenv('SHORTURL_LOGIN_WINDOW_SECONDS') ?: 900),
];
