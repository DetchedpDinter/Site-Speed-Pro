<?php

namespace Sandip\SiteSpeedPro\Admin;

defined('ABSPATH') || exit;

use Sandip\SiteSpeedPro\Core\Plugin;
use Sandip\SiteSpeedPro\Cache\Layer1Cache;
use Sandip\SiteSpeedPro\Cache\Layer2Cache;

class ToolsPage
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_post_sitespeedpro_purge_cache', [self::class, 'handle_purge_cache']);
        add_action('admin_post_sitespeedpro_save_settings', [self::class, 'save_settings']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'tools.php',
            __('Site Speed Pro Tools', 'site-speed-pro'),
            __('Site Speed Pro', 'site-speed-pro'),
            'manage_options',
            'site-speed-pro-tools',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'site-speed-pro'));
        }

        $is_apache = Plugin::is_apache();
        $logging_enabled = get_option('sitespeedpro_enable_logging', '1') === '1';
?>
        <div class="wrap">
            <h1><?php _e('Site Speed Pro Tools', 'site-speed-pro'); ?></h1>

            <h2><?php _e('Cache Purge', 'site-speed-pro'); ?></h2>
            <p>
                <?php echo $is_apache
                    ? __('Apache server detected — Layer 2 (Static HTML Cache) is active.', 'site-speed-pro')
                    : __('Non-Apache server detected — Layer 1 (PHP Transient Cache) is active.', 'site-speed-pro'); ?>
            </p>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sitespeedpro_purge_cache_action'); ?>
                <input type="hidden" name="action" value="sitespeedpro_purge_cache" />
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Clear All Cache', 'site-speed-pro'); ?>" />
            </form>

            <hr style="margin: 40px 0;" />

            <h2><?php _e('Logging', 'site-speed-pro'); ?></h2>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sitespeedpro_save_settings_nonce'); ?>
                <input type="hidden" name="action" value="sitespeedpro_save_settings">

                <label class="sitespeedpro-switch-small" title="<?php esc_attr_e('Enable or disable plugin error logging', 'site-speed-pro'); ?>">
                    <input type="checkbox" name="enable_logging" value="1" <?php checked($logging_enabled); ?>>
                    <span class="sitespeedpro-slider-small"></span>
                </label>
                <span style="margin-left: 10px; vertical-align: middle;"><?php _e('Enable Logging', 'site-speed-pro'); ?></span>

                <?php submit_button(__('Save Logging Settings', 'site-speed-pro'), 'primary', '', false, ['style' => 'margin-left: 20px;']); ?>
            </form>

            <hr style="margin: 40px 0;" />

            <h2><?php _e('Database Cleanup', 'site-speed-pro'); ?></h2>
            <p><?php _e('This tool removes unnecessary database clutter like revisions, trashed posts, and expired transients.', 'site-speed-pro'); ?></p>
            <button id="sitespeedpro-db-clean" class="button button-primary"><?php _e('Run DB Cleanup', 'site-speed-pro'); ?></button>
            <div id="sitespeedpro-db-clean-results" style="margin-top: 1em;"></div>
        </div>

        <style>
            /* Small toggle switch styling */
            .sitespeedpro-switch-small {
                position: relative;
                display: inline-block;
                width: 36px;
                height: 18px;
                vertical-align: middle;
            }

            .sitespeedpro-switch-small input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .sitespeedpro-slider-small {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: 0.4s;
                border-radius: 18px;
            }

            .sitespeedpro-slider-small:before {
                position: absolute;
                content: "";
                height: 14px;
                width: 14px;
                left: 2px;
                bottom: 2px;
                background-color: white;
                transition: 0.4s;
                border-radius: 50%;
            }

            .sitespeedpro-switch-small input:checked+.sitespeedpro-slider-small {
                background-color: #46b450;
            }

            .sitespeedpro-switch-small input:checked+.sitespeedpro-slider-small:before {
                transform: translateX(18px);
            }
        </style>

<?php
    }

    public static function handle_purge_cache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized user', 'site-speed-pro'));
        }

        check_admin_referer('sitespeedpro_purge_cache_action');

        if (Plugin::is_apache()) {
            Layer2Cache::purge_all_cache();
        } else {
            Layer1Cache::purge_all_cache();
        }

        wp_safe_redirect(admin_url('tools.php?page=site-speed-pro-tools'));
        exit;
    }

    public static function save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'site-speed-pro'));
        }

        check_admin_referer('sitespeedpro_save_settings_nonce');

        if (isset($_POST['enable_logging']) && $_POST['enable_logging'] === '1') {
            update_option('sitespeedpro_enable_logging', '1');
        } else {
            update_option('sitespeedpro_enable_logging', '0');
        }

        wp_redirect(add_query_arg(['page' => 'site-speed-pro-tools', 'updated' => 'true'], admin_url('tools.php')));
        exit;
    }

    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'tools_page_site-speed-pro-tools') {
            return;
        }

        wp_enqueue_script(
            'sitespeedpro-db-cleaner',
            plugin_dir_url(SITE_SPEED_PRO_FILE) . 'assets/js/admin-tools.js',
            ['jquery'],
            null,
            true
        );

        wp_localize_script('sitespeedpro-db-cleaner', 'SiteSpeedProDB', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('sitespeedpro_db_clean_nonce'),
        ]);
    }
}
