<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Wires the plugin into yahnis-elsts/plugin-update-checker so each
 * site auto-pulls new releases from
 * https://github.com/TapTap-Pay/woocommerce/releases.
 *
 * We use `enableReleaseAssets()` so PUC downloads the built zip we
 * attach to each tagged release (containing vendor/) rather than the
 * raw source — installing the source tarball would land on a stripped
 * tree with no autoloader and a broken plugin.
 *
 * Pre-releases are intentionally NOT served to stores. The release
 * workflow tags `vX.Y.Z` for production and `vX.Y.Z-betaN` for
 * pre-release; PUC reads the GitHub "prerelease" flag and skips
 * anything with it set unless the store opts in.
 */
final class UpdateChecker
{
    private const REPO_URL = 'https://github.com/TapTap-Pay/woocommerce/';
    private const SLUG = 'taptap-pay';
    /** Release asset name pattern uploaded by .github/workflows/release.yml. */
    private const ASSET_NAME_REGEX = '/^taptap-pay-.*\.zip$/';

    public static function bootstrap(string $plugin_file): void
    {
        if (!class_exists(PucFactory::class)) {
            return;
        }
        $checker = PucFactory::buildUpdateChecker(self::REPO_URL, $plugin_file, self::SLUG);

        // The release artefact uploaded by CI is a zip *containing* the
        // vendor/ tree. PUC default behaviour clones the source repo
        // (no vendor), which would land on a non-functional plugin.
        $checker->getVcsApi()->enableReleaseAssets(self::ASSET_NAME_REGEX);
    }
}
