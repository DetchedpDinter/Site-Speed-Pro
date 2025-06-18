<?php

namespace Sandip\SiteSpeedPro\Utils;

defined('ABSPATH') || exit;

/**
 * Class Logger
 *
 * A simple, file-based logger for the SiteSpeedPro plugin.
 * Logs are written to a `sitespeedpro/logs/error.log` file inside the WordPress uploads directory.
 * Logging can be toggled on/off using the 'sitespeedpro_enable_logging' option.
 */
class Logger
{
    /**
     * Path to the active log file.
     *
     * @var string|null
     */
    private static ?string $log_file = null;

    /**
     * Maximum size (in bytes) before log file is rotated.
     */
    private const MAX_LOG_SIZE = 1048576; // 1 MB

    /**
     * Returns the full path to the log file, creating the log directory if needed.
     *
     * @return string Absolute path to the log file.
     */
    private static function get_log_file(): string
    {
        if (self::$log_file === null) {
            $upload_dir = wp_upload_dir();
            $log_dir = trailingslashit($upload_dir['basedir']) . 'sitespeedpro/logs/';

            if (!is_dir($log_dir)) {
                wp_mkdir_p($log_dir);
            }

            self::$log_file = $log_dir . 'error.log';
        }

        return self::$log_file;
    }

    /**
     * Checks whether logging is enabled via plugin settings.
     *
     * @return bool True if logging is enabled, false otherwise.
     */
    private static function is_logging_enabled(): bool
    {
        // Controlled by a wp_option; defaults to false if not set
        return (bool) get_option('sitespeedpro_enable_logging', false);
    }

    /**
     * Writes a log message to the file with a given severity level.
     *
     * @param string $level   Log level (e.g. ERROR, WARNING, INFO).
     * @param string $message The message to log.
     */
    public static function log(string $level, string $message): void
    {
        if (!self::is_logging_enabled()) {
            return;
        }

        $log_file = self::get_log_file();

        // Rotate log file if it exceeds the max size
        if (file_exists($log_file) && filesize($log_file) > self::MAX_LOG_SIZE) {
            $rotated = $log_file . '.' . date('Ymd-His');
            @rename($log_file, $rotated);
        }

        $date = date('Y-m-d H:i:s');
        $entry = "[$date][$level] $message" . PHP_EOL;

        // Safely write to file with locking
        $fp = fopen($log_file, 'a');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $entry);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Logs a message with ERROR level.
     *
     * @param string $message The message to log.
     */
    public static function error(string $message): void
    {
        self::log('ERROR', $message);
    }

    /**
     * Logs a message with WARNING level.
     *
     * @param string $message The message to log.
     */
    public static function warning(string $message): void
    {
        self::log('WARNING', $message);
    }

    /**
     * Logs a message with INFO level.
     * This should be used sparingly to avoid performance impact.
     *
     * @param string $message The message to log.
     */
    public static function info(string $message): void
    {
        self::log('INFO', $message);
    }
}
