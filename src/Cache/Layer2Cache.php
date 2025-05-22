<?php

namespace Sandip\SiteSpeedPro\Cache;

defined('ABSPATH') or die('No script kiddies please!');

class Layer2Cache
{
    private static string $cache_dir;
    private static string $cache_path = '';
    private static bool $should_cache = true;
    private static bool $cache_hit = false;

    public static function init()
    {
        self::$cache_dir = WP_CONTENT_DIR . '/uploads/sitespeedpro-cache/';
        if (!is_dir(self::$cache_dir)) {
            wp_mkdir_p(self::$cache_dir);
        }

        add_action('admin_init', [self::class, 'maybe_setup_htaccess']);
        add_action('template_redirect', [self::class, 'serve_cache'], 0);
        add_action('shutdown', [self::class, 'save_cache'], 0);

        // Replaced unreliable hooks with a single reliable one
        add_action('wp_after_insert_post', [self::class, 'invalidate_cache_full'], 10, 2);
    }

    public static function maybe_setup_htaccess()
    {
        if (get_transient('sitespeedpro_htaccess_check')) {
            return;
        }

        $htaccess_file = ABSPATH . '.htaccess';
        if (!is_writable($htaccess_file)) {
            error_log('[SiteSpeedPro][Layer2] .htaccess not writable.');
            return;
        }

        $rules = self::get_htaccess_rules();
        $htaccess_contents = file_get_contents($htaccess_file);

        if (strpos($htaccess_contents, '# BEGIN SiteSpeedPro Static Cache') === false) {
            $htaccess_contents .= "\n\n# BEGIN SiteSpeedPro Static Cache\n" . $rules . "\n# END SiteSpeedPro Static Cache\n";
            file_put_contents($htaccess_file, $htaccess_contents);
            error_log('[SiteSpeedPro][Layer2] Added rewrite rules to .htaccess');
        }

        set_transient('sitespeedpro_htaccess_check', 'done', 12 * HOUR_IN_SECONDS);
    }

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

    public static function serve_cache()
    {
        if (is_admin() || is_user_logged_in() || !self::is_get_request()) {
            self::$should_cache = false;
            return;
        }

        self::$cache_path = self::get_cache_file_path();

        if (file_exists(self::$cache_path)) {
            header('X-Cache: HIT (Layer 2)');
            error_log('[SiteSpeedPro][Layer2] Cache HIT for ' . $_SERVER['REQUEST_URI']);
            readfile(self::$cache_path);
            self::$cache_hit = true;
            exit;
        }

        header('X-Cache: MISS (Layer 2)');
        error_log('[SiteSpeedPro][Layer2] Cache MISS for ' . $_SERVER['REQUEST_URI']);
        ob_start();
    }

    public static function save_cache()
    {
        if (!self::$should_cache || self::$cache_hit || empty(self::$cache_path)) {
            return;
        }

        if (get_transient('ssp_layer2_cache_cooldown')) {
            error_log('[SiteSpeedPro][Layer2] Cooldown active, skipping cache save for: ' . $_SERVER['REQUEST_URI']);
            return;
        }

        if (!ob_get_level()) {
            error_log('[SiteSpeedPro][Layer2] No output buffer active.');
            return;
        }

        $content = ob_get_contents();
        if (empty($content)) {
            error_log('[SiteSpeedPro][Layer2] Output buffer empty.');
            ob_end_clean();
            return;
        }

        ob_end_clean();

        $dir = dirname(self::$cache_path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (false === file_put_contents(self::$cache_path, $content)) {
            error_log('[SiteSpeedPro][Layer2] Failed to write cache: ' . self::$cache_path);
            echo $content;
            return;
        }

        error_log('[SiteSpeedPro][Layer2] Saved cache for ' . $_SERVER['REQUEST_URI']);
        echo $content;
    }

    private static function get_cache_file_path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/index';
        $uri = ($uri === '/' || $uri === '') ? 'index' : trim($uri, '/');
        $uri = preg_replace('#[^a-zA-Z0-9/_\-]#', '', $uri);
        return self::$cache_dir . $uri . '/index.html';
    }

    private static function get_cache_file_path_by_url(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/index';
        $path = ($path === '/' || $path === '') ? 'index' : trim($path, '/');
        $path = preg_replace('#[^a-zA-Z0-9/_\-]#', '', $path);
        return self::$cache_dir . $path . '/index.html';
    }

    public static function invalidate_cache_full($post, $update)
    {
        if (wp_is_post_revision($post->ID)) return;

        $url = get_permalink($post);
        if (!$url) return;

        $cache_path = self::get_cache_file_path_by_url($url);
        if (file_exists($cache_path)) {
            unlink($cache_path);
            error_log('[SiteSpeedPro][Layer2] Cache cleared for: ' . $url);
        }

        self::purge_related_pages($post->ID);
    }

    private static function purge_related_pages($post_id)
    {
        error_log('[SiteSpeedPro][Layer2] purge_related_pages() called for post ID: ' . $post_id);
        $post = get_post($post_id);
        if (!$post) return;

        $related_urls = [];

        // Homepage
        $related_urls[] = home_url('/');

        // Post type archive
        if (post_type_exists($post->post_type) && is_post_type_viewable($post->post_type)) {
            $archive_url = get_post_type_archive_link($post->post_type);
            if ($archive_url) {
                $related_urls[] = $archive_url;
            }
        }

        // Author archive
        $author_url = get_author_posts_url($post->post_author);
        if ($author_url) {
            $related_urls[] = $author_url;
        }

        // Term archive pages
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            foreach ($terms as $term) {
                $term_url = get_term_link($term);
                if (!is_wp_error($term_url)) {
                    $related_urls[] = $term_url;
                }
            }
        }

        foreach ($related_urls as $url) {
            $cache_path = self::get_cache_file_path_by_url($url);
            error_log('[SiteSpeedPro][Layer2] Trying to purge: ' . $cache_path);
            if (file_exists($cache_path)) {
                unlink($cache_path);
                error_log('[SiteSpeedPro][Layer2] Related cache purged: ' . $url);
            }
        }
    }

    public static function render_purge_page()
    {
        if (isset($_POST['purge_cache']) && check_admin_referer('purge_cache_action')) {
            self::purge_all_cache();
            echo '<div class="updated"><p>All static HTML cache cleared.</p></div>';
        }

        echo '<div class="wrap"><h1>SiteSpeedPro Static Cache Purge</h1>';
        echo '<form method="POST">';
        wp_nonce_field('purge_cache_action');
        echo '<p><input type="submit" name="purge_cache" class="button button-primary" value="Clear All Static Cache"></p>';
        echo '</form></div>';
    }

    public static function purge_all_cache()
    {
        self::rrmdir(self::$cache_dir);
        error_log('[SiteSpeedPro][Layer2] All static HTML cache cleared.');
        wp_mkdir_p(self::$cache_dir);
    }

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

    private static function is_get_request(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }
}
