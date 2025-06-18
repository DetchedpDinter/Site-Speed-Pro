<?php

/**
 * Plugin Name: Site Speed Pro
 * Description: One-click WordPress caching plugin that optimizes your website performance with layered caching strategies.
 * Version:     1.0.0
 * Author:      Sandip Mishra
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sitespeedpro
 * Domain Path: /languages
 *
 * @package SiteSpeedPro
 */

defined('ABSPATH') or die('No script kiddies please!');

// Define main plugin file constant for internal use
define('SITE_SPEED_PRO_FILE', __FILE__);

// Require Composer autoloader for automatic class loading
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Initialize the plugin once all plugins have loaded.
 */
add_action('plugins_loaded', function () {
    Sandip\SiteSpeedPro\Core\Plugin::init();
});

/**
 * Activation hook - runs when the plugin is activated.
 * Sets an option to show a cache method notice on next admin load.
 */
register_activation_hook(__FILE__, function () {
    update_option('sitespeedpro_show_cache_method_notice', '1');
});
