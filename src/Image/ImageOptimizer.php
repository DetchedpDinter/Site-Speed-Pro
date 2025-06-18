<?php

namespace Sandip\SiteSpeedPro\Image;

defined('ABSPATH') || exit;

use Sandip\SiteSpeedPro\Utils\Logger;

/**
 * Class ImageOptimizer
 *
 * Optimizes images using the resmush.it API on upload and generates WebP fallback
 * if optimization fails. Hooks into the WordPress attachment process.
 */
class ImageOptimizer
{
    /**
     * Initializes the image optimization process.
     *
     * Hooks into 'wp_generate_attachment_metadata' to optimize both original
     * and resized versions of uploaded images.
     */
    public static function init()
    {
        add_filter('wp_generate_attachment_metadata', [self::class, 'optimize_uploaded_images'], 999, 2);
    }

    /**
     * Optimizes uploaded images and all their registered sizes.
     *
     * @param array $metadata Metadata for the uploaded attachment.
     * @param int $attachment_id Attachment ID.
     * @return array Processed metadata.
     */
    public static function optimize_uploaded_images(array $metadata, int $attachment_id): array
    {
        $upload_dir = wp_upload_dir();
        $base_path = trailingslashit($upload_dir['basedir']);
        $original_file = get_attached_file($attachment_id);

        Logger::log("Optimizing original image: $original_file", 'info');
        self::optimize_file($original_file);

        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                $size_file = $base_path . $size['file'];
                Logger::log("Optimizing resized image: $size_file", 'info');
                self::optimize_file($size_file);
            }
        }

        return $metadata;
    }

    /**
     * Sends an image to resmush.it API and replaces the file with its optimized version.
     * Falls back to WebP generation on failure.
     *
     * @param string $file_path Full path to the image file.
     */
    private static function optimize_file(string $file_path): void
    {
        if (!file_exists($file_path) || !is_writable($file_path)) {
            Logger::log("File not found or not writable: $file_path", 'warning');
            return;
        }

        $mime = mime_content_type($file_path);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            Logger::log("Unsupported mime type: $mime for file: $file_path", 'notice');
            return;
        }

        Logger::log("Sending image to resmush.it API: $file_path", 'info');

        $response = wp_remote_post('https://api.resmush.it/ws.php', [
            'body' => [
                'files' => curl_file_create($file_path),
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            Logger::log("resmush.it API request failed. Falling back to WebP: $file_path", 'error');
            self::generate_webp_fallback($file_path);
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['dest'])) {
            $optimized_image = wp_remote_get($body['dest'], ['timeout' => 20]);
            if (!is_wp_error($optimized_image)) {
                file_put_contents($file_path, wp_remote_retrieve_body($optimized_image));
                Logger::log("Image optimized successfully: $file_path", 'success');
            } else {
                Logger::log("Failed to download optimized image. Using WebP fallback: $file_path", 'warning');
                self::generate_webp_fallback($file_path);
            }
        } else {
            Logger::log("resmush.it returned empty result. Generating WebP fallback: $file_path", 'warning');
            self::generate_webp_fallback($file_path);
        }
    }

    /**
     * Generates a WebP version of the image as a fallback optimization method.
     *
     * @param string $file_path Full path to the original image.
     */
    private static function generate_webp_fallback(string $file_path): void
    {
        if (!function_exists('imagewebp')) {
            Logger::log("WebP generation not supported on server.", 'error');
            return;
        }

        $mime = mime_content_type($file_path);
        $image = false;

        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($file_path);
                break;
            case 'image/png':
                $image = imagecreatefrompng($file_path);
                break;
            default:
                Logger::log("Cannot generate WebP for unsupported mime: $mime", 'notice');
                return;
        }

        if (!$image) {
            Logger::log("Failed to create image resource for: $file_path", 'error');
            return;
        }

        $info = pathinfo($file_path);
        $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';

        if (imagewebp($image, $webp_path, 85)) {
            Logger::log("WebP fallback generated: $webp_path", 'success');
        } else {
            Logger::log("Failed to generate WebP for: $file_path", 'error');
        }

        imagedestroy($image);
    }
}
