<?php

namespace Sandip\SiteSpeedPro\Core;

defined('ABSPATH') or die('No script kiddies please!');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Sandip\\SiteSpeedPro\\';
    $base_dir = __DIR__ . '/../';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

class Plugin
{
    public static function init()
    {
        if (self::is_apache()) {
            \Sandip\SiteSpeedPro\Cache\Layer2Cache::init();
            add_action('admin_menu', function () {
                add_submenu_page(
                    'tools.php',
                    __('Purge SiteSpeedPro Cache', 'site-speed-pro'),
                    __('SiteSpeedPro Cache', 'site-speed-pro'),
                    'manage_options',
                    'site-speedpro-cache-layer2',
                    [\Sandip\SiteSpeedPro\Cache\Layer2Cache::class, 'render_purge_page']
                );
            });
        } else {
            \Sandip\SiteSpeedPro\Cache\Layer1Cache::init();
            add_action('admin_menu', function () {
                add_submenu_page(
                    'tools.php',
                    __('Purge SiteSpeedPro Cache', 'site-speed-pro'),
                    __('SiteSpeedPro Cache', 'site-speed-pro'),
                    'manage_options',
                    'site-speedpro-cache-layer1',
                    [\Sandip\SiteSpeedPro\Cache\Layer1Cache::class, 'render_purge_page']
                );
            });
        }

        // Show notice only once after activation
        add_action('admin_notices', [self::class, 'maybe_show_activation_notice']);
    }

    public static function maybe_show_activation_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_option('sitespeedpro_show_cache_method_notice') === '1') {
            if (self::is_apache()) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>SiteSpeedPro:</strong> Apache server detected. Using Layer 2 (Static HTML Cache).</p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>SiteSpeedPro:</strong> Non-Apache server detected. Using Layer 1 (PHP Transient Cache).</p></div>';
            }

            // Delete the flag so it only shows once
            delete_option('sitespeedpro_show_cache_method_notice');
        }
    }

    private static function is_apache(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false;
    }
}
