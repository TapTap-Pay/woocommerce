<?php
// Fails a release whose plugin code imports SDK classes the pinned
// `taptap-pay/sdk` does not have. `php -l` only parses, so on its own it
// passes a plugin that will be fatal on its first checkout. Run from the
// plugin root after `composer install`.
//
// usage: php .github/scripts/check-sdk-imports.php
declare(strict_types=1);

require 'vendor/autoload.php';

/** The fully qualified names a file imports with top-level `use` statements. */
function imported_classes(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $names = [];
    $depth = 0;
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token === '{') {
            $depth++;
        } elseif ($token === '}') {
            $depth--;
        }
        // Depth 0 only: inside a class body `use` names a trait.
        if (!is_array($token) || $token[0] !== T_USE || $depth !== 0) {
            continue;
        }
        // `function () use ($x) {` captures variables; it imports nothing.
        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if (($tokens[$next] ?? null) === '(') {
            continue;
        }
        $statement = '';
        for ($i++; $i < $count && $tokens[$i] !== ';'; $i++) {
            $statement .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }
        $name = ltrim(trim((string) preg_split('/\s+as\s+/i', trim($statement))[0]), '\\');
        if ($name !== '' && !preg_match('/^(function|const)\s/', $name)) {
            $names[] = $name;
        }
    }
    return $names;
}

/** SDK classes only: WordPress, WooCommerce and the plugin's own classes do not load outside a site. */
function from_sdk(string $name): bool
{
    $lower = strtolower($name);
    return str_starts_with($lower, 'taptap\\') && !str_starts_with($lower, 'taptap\\pay\\woocommerce\\');
}

$failures = [];
$checked = 0;
foreach (array_merge(glob('includes/*.php') ?: [], ['uninstall.php']) as $file) {
    foreach (array_filter(imported_classes($file), 'from_sdk') as $name) {
        $checked++;
        if (!class_exists($name) && !interface_exists($name)) {
            $failures[] = "$file: imports $name, which the installed SDK does not have";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n\nSDK import check failed: " . count($failures) . " problem(s)\n");
    exit(1);
}
if ($checked === 0) {
    fwrite(STDERR, "SDK import check failed: found no SDK import to check\n");
    exit(1);
}
echo "SDK import check passed ($checked imports)\n";
