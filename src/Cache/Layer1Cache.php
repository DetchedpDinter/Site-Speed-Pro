<?php

namespace Sandip\SiteSpeedPro\Cache;

defined('ABSPATH') or die('No script kiddies please!');

class Layer1Cache
{
    private static bool $should_cache = true;
    private static bool $cache_hit = false;
    private static bool $skip_logged = false;

    public static function init()
    {
        add_action('template_redirect', [self::class, 'handle_cache'], 0);
        add_action('shutdown', [self::class, 'maybe_save_cache'], 0);

        add_action('save_post', [self::class, 'invalidate_cache']);
        add_action('deleted_post', [self::class, 'invalidate_cache']);
        add_action('trashed_post', [self::class, 'invalidate_cache']);
        add_action('untrashed_post', [self::class, 'invalidate_cache']);
        add_action('transition_post_status', [self::class, 'invalidate_cache_on_status_change'], 10, 3);
    }

    public static function handle_cache()
    {
        $uri = $_SERVER['REQUEST_URI'];

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            self::$should_cache = false;
            error_log('[SiteSpeedPro] Skipping caching: admin/ajax');
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            self::$should_cache = false;
            error_log('[SiteSpeedPro] Skipping caching: REST API request');
            return;
        }

        if (is_user_logged_in()) {
            self::$should_cache = false;
            error_log('[SiteSpeedPro] Skipping caching: User logged in');
            return;
        }

        if (preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|svg)$/i', $uri)) {
            self::$should_cache = false;
            error_log('[SiteSpeedPro] Skipping caching: Static asset');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::$should_cache = false;
            error_log('[SiteSpeedPro] Skipping caching: Non-GET request');
            return;
        }

        // Now proceed to check cache and start buffering...

        $key = self::get_cache_key();
        $cached = get_transient($key);

        if ($cached !== false) {
            header('X-Cache: HIT');
            error_log("[SiteSpeedPro] Cache HIT for: {$uri}");
            echo $cached;
            self::$cache_hit = true;
            exit;
        }

        header('X-Cache: MISS');
        error_log("[SiteSpeedPro] Cache MISS for: {$uri}");
        ob_start();
        self::$should_cache = true;
    }

    public static function maybe_save_cache()
    {
        if (!self::$should_cache || self::$cache_hit) {
            // Don't save cache if we decided not to cache or we already served from cache
            return;
        }

        if (!ob_get_level()) {
            error_log('[SiteSpeedPro] No output buffer active. Skipping cache save.');
            return;
        }

        $content = ob_get_contents();

        if (empty($content)) {
            error_log('[SiteSpeedPro] Output buffer empty. Not saving.');
            ob_end_clean();
            return;
        }

        ob_end_clean();

        $key = self::get_cache_key();

        // Double-check: Do NOT save cache if admin or ajax (extra safeguard)
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            error_log('[SiteSpeedPro] Refusing to save cache for admin or ajax.');
            echo $content;
            return;
        }

        set_transient($key, $content, 12 * HOUR_IN_SECONDS);

        error_log("[SiteSpeedPro] Saved cache for: {$_SERVER['REQUEST_URI']}");
        echo $content;
    }

    private static function get_cache_key(): string
    {
        return 'page_cache_' . md5($_SERVER['REQUEST_URI']);
    }

    private static function get_cache_key_by_url(string $url): string
    {
        return 'page_cache_' . md5(parse_url($url, PHP_URL_PATH) ?? '/');
    }

    public static function invalidate_cache(int $post_id)
    {
        if (wp_is_post_revision($post_id)) return;

        $url = get_permalink($post_id);
        if (!$url) return;

        $key = self::get_cache_key_by_url($url);
        delete_transient($key);
        error_log("[SiteSpeedPro] Cache invalidated for Post ID {$post_id} - URL: {$url}");

        self::invalidate_related_pages($post_id);
    }

    public static function invalidate_cache_on_status_change(string $new_status, string $old_status, $post)
    {
        if ($new_status === $old_status || wp_is_post_revision($post->ID)) return;
        self::invalidate_cache($post->ID);
    }

    private static function invalidate_related_pages(int $post_id)
    {
        $urls = [];

        // Homepage
        $urls[] = home_url('/');

        // Post type archive (if public CPT)
        $post_type = get_post_type($post_id);
        if ($post_type && $post_type !== 'post' && post_type_exists($post_type)) {
            $archive_link = get_post_type_archive_link($post_type);
            if ($archive_link) {
                $urls[] = $archive_link;
            }
        }

        // Categories
        $terms = get_the_terms($post_id, 'category');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link)) {
                    $urls[] = $term_link;
                }
            }
        }

        // Tags
        $tags = get_the_terms($post_id, 'post_tag');
        if ($tags && !is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $tag_link = get_term_link($tag);
                if (!is_wp_error($tag_link)) {
                    $urls[] = $tag_link;
                }
            }
        }

        // Author archive
        $author_id = get_post_field('post_author', $post_id);
        if ($author_id) {
            $urls[] = get_author_posts_url($author_id);
        }

        foreach (array_filter($urls) as $url) {
            $key = self::get_cache_key_by_url($url);
            delete_transient($key);
            error_log("[SiteSpeedPro] Related page cache invalidated: {$url}");
        }
    }

    public static function render_purge_page()
    {
        if (isset($_POST['purge_cache']) && check_admin_referer('purge_cache_action')) {
            self::purge_all_cache();
            echo '<div class="updated"><p>All cache cleared.</p></div>';
        }

        echo '<div class="wrap"><h1>Purge Cache</h1>';
        echo '<form method="POST">';
        wp_nonce_field('purge_cache_action');
        echo '<p><input type="submit" name="purge_cache" class="button button-primary" value="Clear All Cache"></p>';
        echo '</form></div>';
    }

    public static function purge_all_cache()
    {
        global $wpdb;
        $prefix = '_transient_page_cache_';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );

        error_log('[SiteSpeedPro] All page cache cleared.');
    }
}
