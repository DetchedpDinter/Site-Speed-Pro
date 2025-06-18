<?php

namespace Sandip\SiteSpeedPro\Optimization;

use Sandip\SiteSpeedPro\Utils\Logger;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Class LazyLoad
 *
 * Implements lazy loading for images by rewriting image tags in post content.
 * Converts <img src="..."> into <img data-src="..." loading="lazy">,
 * and injects a JavaScript helper to load them when visible.
 */
class LazyLoad
{
    /**
     * Initializes lazy loading by hooking into content filter and enqueueing the loader script.
     */
    public static function init()
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_scripts']);
        add_filter('the_content', [self::class, 'filter_content_for_lazyload'], 999);
        Logger::log('LazyLoad initialized and hooks registered.', 'info');
    }

    /**
     * Enqueues the frontend JavaScript responsible for converting data-src to src.
     */
    public static function enqueue_scripts()
    {
        $src = plugin_dir_url(SITE_SPEED_PRO_FILE) . 'assets/js/frontend-lazyload.js';

        wp_register_script(
            'sitespeedpro-lazyload',
            $src,
            [],
            '1.0.0',
            true
        );
        wp_enqueue_script('sitespeedpro-lazyload');

        Logger::log("LazyLoad JS enqueued: $src", 'info');
    }

    /**
     * Rewrites <img> tags in the post content to use lazy loading.
     *
     * @param string $content The HTML content to filter.
     * @return string Modified content with lazy-loaded images.
     */
    public static function filter_content_for_lazyload($content)
    {
        if (empty($content) || !is_string($content)) {
            return $content;
        }

        libxml_use_internal_errors(true);

        // Convert encoding safely
        $encodedContent = @mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');
        if ($encodedContent === false) {
            Logger::log('Failed to encode content for lazyload.', 'warning');
            $encodedContent = $content;
        }

        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $encodedContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        if (!$loaded) {
            libxml_clear_errors();
            Logger::log('DOM parsing failed for lazy loading images.', 'error');
            return $content;
        }

        $imgs = $dom->getElementsByTagName('img');
        $count = 0;

        foreach ($imgs as $img) {
            if ($img->hasAttribute('loading') || !$img->hasAttribute('src')) {
                continue;
            }

            $src = $img->getAttribute('src');
            $img->removeAttribute('src');

            if ($img->hasAttribute('srcset')) {
                $img->removeAttribute('srcset');
            }

            $img->setAttribute('data-src', $src);
            $img->setAttribute('loading', 'lazy');

            $existingClass = $img->getAttribute('class');
            $img->setAttribute('class', trim($existingClass . ' sitespeedpro-lazy'));

            $count++;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $newContent = '';

        if ($body) {
            foreach ($body->childNodes as $child) {
                $newContent .= $dom->saveHTML($child);
            }
        } else {
            $newContent = $dom->saveHTML();
        }

        libxml_clear_errors();

        Logger::log("LazyLoad modified $count <img> tag(s) in post content.", 'debug');

        return $newContent;
    }
}
