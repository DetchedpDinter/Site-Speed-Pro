<?php

namespace Sandip\SiteSpeedPro\Cache;

use Sandip\SiteSpeedPro\Utils\Logger;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Layer 2 Cache: Static HTML caching using Apache and .htaccess rules.
 */
class Layer2Cache
{
    private static string $cache_dir;
    private static string $cache_path = '';
    private static bool $should_cache = true;
    private static bool $cache_hit = false;

    /**
     * Initialize the Layer 2 cache system.
     */
    public static function init()
    {
        self::$cache_dir = WP_CONTENT_DIR . '/uploads/sitespeedpro-cache/';
        if (!is_dir(self::$cache_dir)) {
            wp_mkdir_p(self::$cache_dir);
        }

        add_action('admin_init', [self::class, 'maybe_setup_htaccess']);
        add_action('template_redirect', [self::class, 'serve_cache'], 0);
        add_action('shutdown', [self::class, 'save_cache'], 0);
        add_action('wp_after_insert_post', [self::class, 'invalidate_cache_full'], 10, 2);
    }

    /**
     * Set up Apache .htaccess rules if missing.
     */
    public static function maybe_setup_htaccess()
    {
        $htaccess_file = ABSPATH . '.htaccess';
        $rules = self::get_htaccess_rules();

        if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) {
            Logger::warning('[Layer2] .htaccess missing or not writable.');
            return;
        }

        $htaccess_contents = file_get_contents($htaccess_file);

        if (strpos($htaccess_contents, '# BEGIN SiteSpeedPro Static Cache') === false) {
            $htaccess_contents .= "\n\n# BEGIN SiteSpeedPro Static Cache\n" . $rules . "\n# END SiteSpeedPro Static Cache\n";
            file_put_contents($htaccess_file, $htaccess_contents);
            Logger::info('[Layer2] Added rewrite rules to .htaccess');
        }

        set_transient('sitespeedpro_htaccess_check', 'done', 6 * HOUR_IN_SECONDS);
    }

    /**
     * Generate Apache rewrite rules for the static cache.
     */
    private static function get_htaccess_rules(): string
    {
        $relative = str_replace(ABSPATH, '/', self::$cache_dir);
        $relative = trim($relative, '/');

        return <<<RULES
RewriteEngine On
RewriteCond %{REQUEST_METHOD} GET
RewriteCond %{REQUEST_URI} !^/wp-admin
RewriteCond %{REQUEST_URI} !^/wp-login.php
RewriteCond %{REQUEST_URI} !^/wp-cron.php
RewriteCond %{REQUEST_URI} !^/index.php
RewriteCond %{DOCUMENT_ROOT}/$relative%{REQUEST_URI}/index.html -f
RewriteRule ^(.*)$ /$relative/%{REQUEST_URI}/index.html [L]
RULES;
    }

    /**
     * Serve a cached HTML file if one exists.
     */
    public static function serve_cache()
    {
        if (is_admin() || is_user_logged_in() || !self::is_get_request()) {
            self::$should_cache = false;
            return;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('/\.(js|css|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot|otf|map)(\?.*)?$/i', $uri)) {
            self::$should_cache = false;
            return;
        }

        self::$cache_path = self::get_cache_file_path();

        if (file_exists(self::$cache_path)) {
            header('X-Cache: HIT (Layer 2)');
            Logger::info('[Layer2] Cache HIT: ' . $_SERVER['REQUEST_URI']);
            readfile(self::$cache_path);
            self::$cache_hit = true;
            exit;
        }

        header('X-Cache: MISS (Layer 2)');
        Logger::info('[Layer2] Cache MISS: ' . $_SERVER['REQUEST_URI']);
        ob_start();
    }

    /**
     * Save generated page output to static HTML cache.
     */
    public static function save_cache()
    {
        if (!self::$should_cache || self::$cache_hit || empty(self::$cache_path)) {
            return;
        }

        if (get_transient('ssp_layer2_cache_cooldown')) {
            Logger::info('[Layer2] Cache save skipped (cooldown active): ' . $_SERVER['REQUEST_URI']);
            return;
        }

        if (ob_get_level() === 0) {
            Logger::warning('[Layer2] No output buffer active.');
            return;
        }

        $content = ob_get_contents();
        ob_end_clean();

        if (empty($content)) {
            Logger::warning('[Layer2] Empty buffer; skipping cache save.');
            return;
        }

        $dir = dirname(self::$cache_path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (false === file_put_contents(self::$cache_path, $content)) {
            Logger::error('[Layer2] Failed to write cache: ' . self::$cache_path);
            echo $content;
            return;
        }

        Logger::info('[Layer2] Cache saved: ' . $_SERVER['REQUEST_URI']);
        echo $content;
    }

    /**
     * Get the cache file path for the current request.
     */
    private static function get_cache_file_path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/index';
        $uri = ($uri === '/' || $uri === '') ? 'index' : trim($uri, '/');
        $uri = preg_replace('#[^a-zA-Z0-9/_\-]#', '', $uri);
        return self::$cache_dir . $uri . '/index.html';
    }

    /**
     * Get the cache path for a specific URL.
     */
    private static function get_cache_file_path_by_url(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/index';
        $path = ($path === '/' || $path === '') ? 'index' : trim($path, '/');
        $path = preg_replace('#[^a-zA-Z0-9/_\-]#', '', $path);
        return self::$cache_dir . $path . '/index.html';
    }

    /**
     * Invalidate cache for a specific post and related pages.
     */
    public static function invalidate_cache_full($post, $update)
    {
        if (wp_is_post_revision($post->ID)) return;

        $url = get_permalink($post);
        if (!$url) return;

        $cache_path = self::get_cache_file_path_by_url($url);
        if (file_exists($cache_path)) {
            unlink($cache_path);
            Logger::info('[Layer2] Cache cleared for: ' . $url);
        }

        self::purge_related_pages($post->ID);
    }

    /**
     * Remove related cache files (home, archive, taxonomy, author).
     */
    private static function purge_related_pages($post_id)
    {
        $post = get_post($post_id);
        if (!$post) return;

        $related_urls = [home_url('/')];

        if (post_type_exists($post->post_type) && is_post_type_viewable($post->post_type)) {
            $archive_url = get_post_type_archive_link($post->post_type);
            if ($archive_url) $related_urls[] = $archive_url;
        }

        $author_url = get_author_posts_url($post->post_author);
        if ($author_url) $related_urls[] = $author_url;

        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            foreach ($terms as $term) {
                $term_url = get_term_link($term);
                if (!is_wp_error($term_url)) $related_urls[] = $term_url;
            }
        }

        foreach ($related_urls as $url) {
            $cache_path = self::get_cache_file_path_by_url($url);
            if (file_exists($cache_path)) {
                unlink($cache_path);
                Logger::info('[Layer2] Related cache purged: ' . $url);
            }
        }
    }

    /**
     * Recursively delete all cached HTML files.
     */
    public static function purge_all_cache()
    {
        self::rrmdir(self::$cache_dir);
        Logger::info('[Layer2] All static HTML cache cleared.');
        wp_mkdir_p(self::$cache_dir);
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private static function rrmdir(string $dir)
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $object) {
            if ($object === '.' || $object === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $object;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Checks if the request is a GET request.
     */
    private static function is_get_request(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }
}
