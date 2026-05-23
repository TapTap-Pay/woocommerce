<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Thin shim around `wc_get_logger()` with a fixed source. Centralised
 * so every "taptap-pay" log line shows up under WooCommerce → Status →
 * Logs with the same source filter.
 */
final class Logger
{
    public const SOURCE = 'taptap-pay';

    private function __construct()
    {
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    private static function log(string $level, string $message, array $context): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        $line = $message;
        if ($context !== []) {
            $line .= ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        wc_get_logger()->log($level, $line, ['source' => self::SOURCE]);
    }
}
