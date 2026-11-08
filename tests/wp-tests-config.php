<?php

/**
 * WordPress test suite configuration. The tests install WordPress and WooCommerce into their own
 * database and empty it on every run: never point this at a shop's database.
 *
 * Override in tests/config.local.php (not committed), which returns an array with any of:
 * abspath (WordPress core), woocommerce (WooCommerce plugin directory), db_name, db_user,
 * db_password, db_host. Environment variables WP_TESTS_ABSPATH, WOOCOMMERCE_DIR, WP_TESTS_DB_NAME,
 * WP_TESTS_DB_USER, WP_TESTS_DB_PASSWORD and WP_TESTS_DB_HOST work too.
 */

$local = is_file(__DIR__.'/config.local.php') ? require __DIR__.'/config.local.php' : [];
$get   = static fn (string $key, string $env, string $default): string => (string) ($local[$key] ?? (getenv($env) ?: $default));

define('ABSPATH', rtrim($get('abspath', 'WP_TESTS_ABSPATH', dirname(__DIR__, 4)), '/').'/');
define('WOOCOMMERCE_PLUGIN_DIR', rtrim($get('woocommerce', 'WOOCOMMERCE_DIR', dirname(__DIR__, 2).'/woocommerce'), '/'));

define('DB_NAME', $get('db_name', 'WP_TESTS_DB_NAME', 'woo_test'));
define('DB_USER', $get('db_user', 'WP_TESTS_DB_USER', 'root'));
define('DB_PASSWORD', $get('db_password', 'WP_TESTS_DB_PASSWORD', ''));
define('DB_HOST', $get('db_host', 'WP_TESTS_DB_HOST', 'localhost'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Shop');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
define('WP_DEBUG', true);
