<?php
/**
 * Script tags.
 *
 * Order matters: vendor libraries first, then the first-party modules in the
 * order config/assets.php declares, which is dependency order.
 *
 * @var \App\Core\View\ViewEngine $this
 */
$version = (string) config('assets.version', '1.0.0');
$useCdn  = (bool) config('assets.use_cdn', false);

/** @var array<string,array{local:string,cdn:string,type:string}> $vendor */
$vendor = (array) config('assets.vendor', []);

foreach ($vendor as $definition) {
    if (($definition['type'] ?? '') !== 'js') {
        continue;
    }

    $local = (string) ($definition['local'] ?? '');

    if (is_file(base_path('public/' . $local))) {
        printf('<script src="%s"></script>' . "\n", e(asset($local) . '?v=' . $version));

        continue;
    }

    if ($useCdn && ($definition['cdn'] ?? '') !== '') {
        printf('<script src="%s" crossorigin="anonymous"></script>' . "\n", e((string) $definition['cdn']));
    }
}

foreach ((array) config('assets.app.js', []) as $script) {
    printf('<script src="%s"></script>' . "\n", e(asset((string) $script) . '?v=' . $version));
}
