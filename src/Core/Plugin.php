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
        // Initialize Lazy Load first, only frontend & non-logged-in users
        if (!is_admin() && !is_user_logged_in()) {
            \Sandip\SiteSpeedPro\Optimization\LazyLoad::init();
        }

        // Init only the correct caching layer (but no admin page here anymore)
        if (self::is_apache()) {
            \Sandip\SiteSpeedPro\Cache\Layer2Cache::init();
        } else {
            \Sandip\SiteSpeedPro\Cache\Layer1Cache::init();
        }

        \Sandip\SiteSpeedPro\Image\ImageOptimizer::init();
        \Sandip\SiteSpeedPro\Optimization\AssetMinifier::init();
        \Sandip\SiteSpeedPro\Optimization\JavaScriptDelay::init();
        \Sandip\SiteSpeedPro\Admin\ToolsPage::init();
        \Sandip\SiteSpeedPro\Database\DatabaseCleaner::init();

        add_action('admin_notices', [self::class, 'maybe_show_activation_notice']);
    }

    public static function maybe_show_activation_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_option('sitespeedpro_show_cache_method_notice') === '1') {
            $message = '<strong>🎉 SiteSpeedPro is now active!</strong><br>';

            if (self::is_apache()) {
                $message .= 'Static HTML caching is enabled for blazing-fast page loads.';
            } else {
                $message .= 'Smart dynamic caching is enabled to speed up your website instantly.';
            }

            $message .= '<br>🚀 Optimize your site further under <strong>Tools → Site Speed Pro</strong>.';

            echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';

            delete_option('sitespeedpro_show_cache_method_notice');
        }
    }

    public static function is_apache(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false;
    }
}
