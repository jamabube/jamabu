<?php
/**
 * Stylesheet links.
 *
 * Vendor libraries are served from public/assets/vendor on the local network.
 * A missing library is skipped rather than emitting a broken link, and the
 * first-party stylesheets carry enough of their own layout that the interface
 * stays usable if Bootstrap or AdminLTE was never fetched.
 *
 * @var \App\Core\View\ViewEngine $this
 */
$version = (string) config('assets.version', '1.0.0');
$useCdn  = (bool) config('assets.use_cdn', false);

/** @var array<string,array{local:string,cdn:string,type:string}> $vendor */
$vendor = (array) config('assets.vendor', []);

foreach ($vendor as $definition) {
    if (($definition['type'] ?? '') !== 'css') {
        continue;
    }

    $local = (string) ($definition['local'] ?? '');
    $path  = base_path('public/' . $local);

    if (is_file($path)) {
        printf('<link rel="stylesheet" href="%s">' . "\n", e(asset($local) . '?v=' . $version));

        continue;
    }

    if ($useCdn && ($definition['cdn'] ?? '') !== '') {
        printf('<link rel="stylesheet" href="%s" crossorigin="anonymous">' . "\n", e((string) $definition['cdn']));
    }
}

foreach ((array) config('assets.app.css', []) as $stylesheet) {
    printf('<link rel="stylesheet" href="%s">' . "\n", e(asset((string) $stylesheet) . '?v=' . $version));
}
