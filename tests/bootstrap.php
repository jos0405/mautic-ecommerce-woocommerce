<?php

declare(strict_types=1);

/**
 * Runs the tests inside a real WordPress with WooCommerce (wp-phpunit), against the database in
 * tests/wp-tests-config.php. Outgoing HTTP is captured by tests/Integration/TestCase.php.
 */

require dirname(__DIR__).'/vendor/autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG='.__DIR__.'/wp-tests-config.php');

$testsDir = getenv('WP_PHPUNIT__DIR');
require $testsDir.'/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require WOOCOMMERCE_PLUGIN_DIR.'/woocommerce.php';
    require dirname(__DIR__).'/mautic-ecommerce-woocommerce.php';
});

// Create WooCommerce's tables and roles once WordPress is installed.
tests_add_filter('setup_theme', static function (): void {
    \WC_Install::install();
    $GLOBALS['wp_roles'] = null;
    wp_roles();
});

require $testsDir.'/includes/bootstrap.php';
require __DIR__.'/Integration/TestCase.php';
