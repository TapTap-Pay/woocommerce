<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Strongly-typed snapshot of the gateway's persisted settings. Reading
 * `get_option()` directly inside every collaborator was the previous
 * pattern; centralising it here keeps the option-key + sandbox-vs-live
 * logic in one place and lets the Gateway form just describe field
 * shapes without re-implementing the lookup.
 */
final class Settings
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $title,
        public readonly string $description,
        public readonly string $apiKey,
        public readonly string $walletId,
        public readonly string $baseUrl,
        public readonly string $webhookSecret,
        public readonly string $webhookId,
    ) {
    }

    /** Loads from WC's persistent gateway settings option. */
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
            baseUrl: self::normalise_url((string) ($raw['base_url'] ?? '')),
            webhookSecret: (string) ($raw['webhook_secret'] ?? ''),
            webhookId: (string) ($raw['webhook_id'] ?? ''),
        );
    }

    /**
     * Update a subset of fields in the persisted option. Used by the
     * webhook provisioner to write back the secret + id without
     * stomping the rest of the form.
     *
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

    public function effective_base_url(): string
    {
        return $this->baseUrl !== '' ? $this->baseUrl : \TapTap\Pay\Options::DEFAULT_BASE_URL;
    }

    private static function normalise_url(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }
        return rtrim($trimmed, '/');
    }
}
