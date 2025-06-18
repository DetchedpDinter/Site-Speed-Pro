<?php

namespace Sandip\SiteSpeedPro\Cache;

use Sandip\SiteSpeedPro\Utils\Logger;

defined('ABSPATH') or die('No script kiddies please!');

class Layer1Cache
{
    private static bool $should_cache = true;
    private static bool $cache_hit = false;

    /**
     * Initialize hooks for cache handling and invalidation.
     */
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

    /**
     * Handles serving the cache or starting output buffering for new cache generation.
     * Skips caching for admin, ajax, logged-in users, non-GET requests, static assets, or REST API.
     *
     * @return void
     */
    public static function handle_cache()
    {
        $uri = $_SERVER['REQUEST_URI'];

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST) || is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'GET' || preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|svg)$/i', $uri)) {
            self::$should_cache = false;
            return; // No logging here to keep logs clean and minimal
        }

        $key = self::get_cache_key();
        $cached = get_transient($key);

        if ($cached !== false) {
            header('X-Cache: HIT');
            Logger::info("Cache HIT for URI: {$uri}");
            echo $cached;
            self::$cache_hit = true;
            exit;
        }

        header('X-Cache: MISS');
        Logger::info("Cache MISS for URI: {$uri}");
        ob_start();
        self::$should_cache = true;
    }

    /**
     * Save the buffered output as transient cache if applicable.
     *
     * @return void
     */
    public static function maybe_save_cache()
    {
        if (!self::$should_cache || self::$cache_hit) {
            return;
        }

        if (!ob_get_level()) {
            Logger::warning('No output buffer active; skipping cache save.');
            return;
        }

        $content = ob_get_contents();

        if (empty($content)) {
            Logger::warning('Output buffer empty; not saving cache.');
            ob_end_clean();
            return;
        }

        ob_end_clean();

        // Double check to avoid caching admin/ajax content
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            echo $content;
            return;
        }

        $key = self::get_cache_key();
        set_transient($key, $content, 12 * HOUR_IN_SECONDS);

        Logger::info('Cache saved for URI: ' . $_SERVER['REQUEST_URI']);
        echo $content;
    }

    /**
     * Generate a cache key based on the current request URI.
     *
     * @return string Cache key.
     */
    private static function get_cache_key(): string
    {
        return 'page_cache_' . md5($_SERVER['REQUEST_URI']);
    }

    /**
     * Generate a cache key based on a specific URL.
     *
     * @param string $url URL to generate key for.
     * @return string Cache key.
     */
    private static function get_cache_key_by_url(string $url): string
    {
        return 'page_cache_' . md5(parse_url($url, PHP_URL_PATH) ?? '/');
    }

    /**
     * Invalidate cache for a specific post and related pages.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public static function invalidate_cache(int $post_id)
    {
        if (wp_is_post_revision($post_id)) {
            return;
        }

        $url = get_permalink($post_id);
        if (!$url) {
            return;
        }

        $key = self::get_cache_key_by_url($url);
        delete_transient($key);

        Logger::info("Cache invalidated for Post ID {$post_id} - URL: {$url}");

        self::invalidate_related_pages($post_id);
    }

    /**
     * Invalidate cache if post status changes.
     *
     * @param string $new_status New post status.
     * @param string $old_status Old post status.
     * @param object $post Post object.
     * @return void
     */
    public static function invalidate_cache_on_status_change(string $new_status, string $old_status, $post)
    {
        if ($new_status === $old_status || wp_is_post_revision($post->ID)) {
            return;
        }
        self::invalidate_cache($post->ID);
    }

    /**
     * Invalidate caches for related pages: homepage, archives, taxonomies, author archives.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    private static function invalidate_related_pages(int $post_id)
    {
        $urls = [];

        // Homepage
        $urls[] = home_url('/');

        // Post type archive (for public CPT)
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
            Logger::info("Related page cache invalidated: {$url}");
        }
    }

    /**
     * Purge all page caches (all transients with the prefix).
     *
     * @return void
     */
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

        Logger::info('All page cache cleared.');
    }
}
