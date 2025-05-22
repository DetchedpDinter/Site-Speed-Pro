<?php

namespace SiteSpeedPro\Admin;

use SiteSpeedPro\Cache\Layer1Cache;

defined('ABSPATH') || exit;

class ToolsPage
{
    private Layer1Cache $cache;

    public function __construct(Layer1Cache $cache)
    {
        $this->cache = $cache;
    }

    public function init(): void
    {
        add_action('admin_menu', [$this, 'add_tools_submenu']);
        add_action('admin_post_ssp_clear_cache', [$this, 'handle_clear_cache']);
    }

    public function add_tools_submenu(): void
    {
        add_submenu_page(
            'tools.php',
            __('Site Speed Pro Cache', 'site-speed-pro'),
            __('Site Speed Pro Cache', 'site-speed-pro'),
            'manage_options',
            'ssp-cache',
            [$this, 'render_tools_page']
        );
    }

    public function render_tools_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'site-speed-pro'));
        }
?>
        <div class="wrap">
            <h1><?php esc_html_e('Site Speed Pro Cache Management', 'site-speed-pro'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ssp_clear_cache_nonce', 'ssp_clear_cache_nonce_field'); ?>
                <input type="hidden" name="action" value="ssp_clear_cache">
                <p>
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Clear Transient Cache', 'site-speed-pro'); ?>">
                </p>
            </form>
        </div>
<?php
    }

    public function handle_clear_cache(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'site-speed-pro'));
        }

        if (! isset($_POST['ssp_clear_cache_nonce_field']) || ! wp_verify_nonce($_POST['ssp_clear_cache_nonce_field'], 'ssp_clear_cache_nonce')) {
            wp_die(__('Nonce verification failed', 'site-speed-pro'));
        }

        // Clear all transients that start with 'ssp_layer1_cache_'
        global $wpdb;
        $transient_prefix = '_transient_ssp_layer1_cache_';
        $like = $wpdb->esc_like($transient_prefix) . '%';

        // Delete from options table (WordPress stores transients in options)
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );

        // Also delete expired transients (optional cleanup)
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_ssp_layer1_cache_%'
            )
        );

        // Redirect back with success message
        wp_redirect(add_query_arg(['page' => 'ssp-cache', 'ssp_cache_cleared' => '1'], admin_url('tools.php')));
        exit();
    }
}
