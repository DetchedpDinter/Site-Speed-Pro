<?php

/**
 * Plugin Name: Site Speed Pro
 * Description: Advanced WordPress page caching plugin with transient-based and static HTML caching.
 * Version: 1.0.0
 * Author: Sandip
 */

defined('ABSPATH') or die('No script kiddies please!');

require_once __DIR__ . '/src/Core/Plugin.php';

add_action('plugins_loaded', function () {
    \Sandip\SiteSpeedPro\Core\Plugin::init();
});

register_activation_hook(__FILE__, function () {
    // ✅ Set transient on activation only
    set_transient('sitespeedpro_show_cache_method_notice', true, 60);
});
