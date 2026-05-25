<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

final class Settings
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $title,
        public readonly string $description,
        public readonly string $apiKey,
        public readonly string $walletId,
        public readonly string $mode,
        public readonly string $webhookSecret,
        public readonly string $webhookId,
    ) {
    }

    public static function load(): self
    {
        $raw = get_option(Plugin::OPTION_KEY, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        return new self(
            enabled: ($raw['enabled'] ?? 'no') === 'yes',
            title: (string) ($raw['title'] ?? __('TapTap Pay', 'taptap-pay')),
            description: (string) ($raw['description'] ?? __('Pay securely via TapTap Pay.', 'taptap-pay')),
            apiKey: (string) ($raw['api_key'] ?? ''),
            walletId: (string) ($raw['wallet_id'] ?? ''),
            mode: (string) ($raw['mode'] ?? 'production'),
            webhookSecret: (string) ($raw['webhook_secret'] ?? ''),
            webhookId: (string) ($raw['webhook_id'] ?? ''),
        );
    }

    /**
     * @param array<string, string|bool|null> $patch
     */
    public static function persist_patch(array $patch): void
    {
        $current = get_option(Plugin::OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }
        foreach ($patch as $k => $v) {
            $current[$k] = $v;
        }
        update_option(Plugin::OPTION_KEY, $current, false);
    }

    public function is_configured(): bool
    {
        return $this->apiKey !== '' && $this->walletId !== '';
    }

    public function api_url(): string
    {
        return $this->mode === 'sandbox'
            ? Gateway::SANDBOX_API_URL
            : Gateway::PROD_API_URL;
    }

    public function ui_url(): string
    {
        return $this->mode === 'sandbox'
            ? Gateway::SANDBOX_UI_URL
            : Gateway::PROD_UI_URL;
    }
}
