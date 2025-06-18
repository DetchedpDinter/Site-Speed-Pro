<?php

namespace Sandip\SiteSpeedPro\Database;

defined('ABSPATH') || exit;

use Sandip\SiteSpeedPro\Utils\Logger;

/**
 * Class DatabaseCleaner
 *
 * Provides database cleanup routines for WordPress by removing unnecessary data
 * like revisions, auto-drafts, trashed posts/comments, spam, orphaned metadata,
 * and expired transients. Executed via AJAX.
 */
class DatabaseCleaner
{
    /**
     * Registers the AJAX handler for running the cleanup.
     */
    public static function init(): void
    {
        add_action('wp_ajax_sitespeedpro_db_clean', [self::class, 'handle']);
    }

    /**
     * Handles the AJAX request to perform database cleanup.
     *
     * Validates permissions, nonce, and executes multiple SQL DELETE queries
     * to clean up unneeded records, then returns a summary as JSON.
     */
    public static function handle(): void
    {
        check_ajax_referer('sitespeedpro_db_clean_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            Logger::log('Unauthorized database clean attempt.', 'warning');
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        global $wpdb;

        Logger::log('Starting database cleanup process.', 'info');

        $results = [
            'post_revisions' => $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'"),
            'auto_drafts' => $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"),
            'trashed_posts' => $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'"),
            'spam_comments' => $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
            'trashed_comments' => $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'"),
            'orphan_postmeta' => $wpdb->query("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.ID IS NULL
            "),
            'orphan_commentmeta' => $wpdb->query("
                DELETE cm FROM {$wpdb->commentmeta} cm
                LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                WHERE c.comment_ID IS NULL
            "),
            'expired_transients' => $wpdb->query("
                DELETE FROM {$wpdb->options}
                WHERE option_name LIKE '\_transient\_timeout\_%'
                AND option_value < UNIX_TIMESTAMP()
            "),
        ];

        Logger::log('Database cleanup completed.', 'success', $results);

        wp_send_json_success($results);
    }
}
