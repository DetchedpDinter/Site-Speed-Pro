<?php

namespace Sandip\SiteSpeedPro\Optimization;

use Sandip\SiteSpeedPro\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * Class JavaScriptDelay
 *
 * Delays execution of specific inline JavaScript that matches certain keywords
 * by converting them to type="text/plain" and adding a loader to restore them.
 */
class JavaScriptDelay
{
    /**
     * Keywords used to identify scripts to delay.
     *
     * @var array
     */
    private static $keywords = ['gtag', 'adsbygoogle', 'googletag', 'analytics', 'facebook'];

    /**
     * Initializes the JavaScript delay feature by hooking into content and enqueueing loader.
     */
    public static function init()
    {
        add_filter('the_content', [self::class, 'delay_inline_scripts'], 99);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_delay_loader']);
        Logger::log('JavaScriptDelay initialized. Keywords: ' . implode(', ', self::$keywords), 'info');
    }

    /**
     * Filters inline <script> tags in post content and delays execution for matching keywords.
     *
     * @param string $content The post content.
     * @return string Modified content with delayed scripts.
     */
    public static function delay_inline_scripts($content)
    {
        return preg_replace_callback('#<script(.*?)>(.*?)</script>#is', function ($matches) {
            $scriptContent = $matches[2];

            foreach (self::$keywords as $keyword) {
                if (stripos($scriptContent, $keyword) !== false) {
                    Logger::log("Script delayed due to keyword match: $keyword", 'debug');
                    return '<script data-delay type="text/plain">' . $scriptContent . '</script>';
                }
            }

            return $matches[0];
        }, $content);
    }

    /**
     * Enqueues the JavaScript loader that converts delayed scripts back to executable ones.
     */
    public static function enqueue_delay_loader()
    {
        $src = plugin_dir_url(SITE_SPEED_PRO_FILE) . 'assets/js/delay-loader.js';

        wp_enqueue_script(
            'sitespeedpro-js-delay-loader',
            $src,
            [],
            '1.0.0',
            true
        );

        Logger::log("Delay loader script enqueued: $src", 'info');
    }
}
