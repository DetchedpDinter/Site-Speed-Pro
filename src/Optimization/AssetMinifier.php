<?php

namespace Sandip\SiteSpeedPro\Optimization;

use MatthiasMullie\Minify;
use Sandip\SiteSpeedPro\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * Class AssetMinifier
 *
 * Handles on-the-fly minification of local JavaScript and CSS assets.
 * Uses Matthias Mullie Minify library and caches results in a writable directory.
 */
class AssetMinifier
{
    /**
     * Directory path where minified files will be cached.
     *
     * @var string
     */
    private static $cache_dir;

    /**
     * Initializes the minifier and sets up WordPress filters.
     */
    public static function init()
    {
        self::$cache_dir = plugin_dir_path(dirname(__DIR__)) . 'cache/minify/';

        if (!is_dir(self::$cache_dir)) {
            wp_mkdir_p(self::$cache_dir);
            Logger::log("Minify cache directory created: " . self::$cache_dir, 'info');
        }

        add_filter('script_loader_src', [self::class, 'maybe_minify_js'], 999);
        add_filter('style_loader_src', [self::class, 'maybe_minify_css'], 999);
    }

    /**
     * Attempts to minify a JS file.
     *
     * @param string $src The script URL.
     * @return string Modified or original URL.
     */
    public static function maybe_minify_js($src)
    {
        return self::handle_minification($src, 'js');
    }

    /**
     * Attempts to minify a CSS file.
     *
     * @param string $src The stylesheet URL.
     * @return string Modified or original URL.
     */
    public static function maybe_minify_css($src)
    {
        return self::handle_minification($src, 'css');
    }

    /**
     * Handles the actual minification logic and caching.
     *
     * @param string $src The source URL.
     * @param string $type Either 'js' or 'css'.
     * @return string Minified file URL or original.
     */
    private static function handle_minification($src, $type)
    {
        if (!self::should_minify($src, $type)) {
            return $src;
        }

        $local_path = self::get_local_path($src);
        if (!file_exists($local_path) || !is_readable($local_path)) {
            Logger::log("File not found or unreadable: $local_path", 'warning');
            return $src;
        }

        $cache_file = self::get_cache_filename($local_path, $type);

        try {
            if (!file_exists($cache_file) || filemtime($cache_file) < filemtime($local_path)) {
                if ($type === 'js') {
                    $minifier = new Minify\JS($local_path);
                } else {
                    $minifier = new Minify\CSS($local_path);
                }

                $minified = $minifier->minify();
                file_put_contents($cache_file, $minified);
                Logger::log("Asset minified: $local_path → $cache_file", 'success');
            }

            if (!file_exists($cache_file) || !is_readable($cache_file) || filesize($cache_file) === 0) {
                Logger::log("Minified file invalid: $cache_file", 'warning');
                return $src;
            }

            return self::get_cache_url($cache_file);
        } catch (\Exception $e) {
            Logger::log("Minification error for $local_path: " . $e->getMessage(), 'error');
            return $src;
        }
    }

    /**
     * Determines whether a file should be minified.
     *
     * @param string $src Asset URL.
     * @param string $type 'js' or 'css'.
     * @return bool True if it qualifies for minification.
     */
    private static function should_minify($src, $type)
    {
        if (strpos($src, home_url()) !== 0) {
            return false; // Only local files
        }

        if (strpos($src, '.min.' . $type) !== false) {
            return false; // Skip already minified
        }

        if (!preg_match('/\.' . $type . '$/', $src)) {
            return false;
        }

        if (strpos($src, '?') !== false || strpos($src, '#') !== false) {
            return false; // Avoid query strings
        }

        $local_path = self::get_local_path($src);
        return file_exists($local_path);
    }

    /**
     * Converts a URL into a server file path.
     *
     * @param string $src Asset URL.
     * @return string Local file path.
     */
    private static function get_local_path($src)
    {
        $home_url = home_url();
        $relative_path = str_replace($home_url, '', $src);
        $relative_path = ltrim($relative_path, '/');
        return ABSPATH . $relative_path;
    }

    /**
     * Generates a cache filename based on file path and type.
     *
     * @param string $local_path Local file path.
     * @param string $type File type (js/css).
     * @return string Full cache file path.
     */
    private static function get_cache_filename($local_path, $type)
    {
        $hash = md5($local_path);
        return self::$cache_dir . $hash . '.' . $type;
    }

    /**
     * Converts a cache file path into a public URL.
     *
     * @param string $cache_file Full path to cached file.
     * @return string Public URL of cached file.
     */
    private static function get_cache_url($cache_file)
    {
        $plugin_dir_path = plugin_dir_path(dirname(__DIR__));
        $plugin_dir_url = plugin_dir_url(dirname(__DIR__));

        $relative = str_replace($plugin_dir_path, '', $cache_file);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        return $plugin_dir_url . $relative;
    }
}
