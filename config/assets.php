<?php

declare(strict_types=1);

/**
 * Front-end asset configuration.
 *
 * The system is designed for an isolated LAN, therefore third-party libraries
 * are served from public/assets/vendor. Run `php bin/console assets:fetch`
 * once on a machine with internet access to populate that directory; the
 * interface degrades gracefully (built-in fallbacks) when a library is absent.
 */
return [
    'use_cdn' => (bool) env('ASSETS_USE_CDN', false),
    'version' => env('ASSETS_VERSION', env('APP_VERSION', '1.0.0')),

    /*
     * Each vendor library declares the local file that must exist and the
     * upstream URL used by the assets:fetch command.
     */
    'vendor' => [
        'bootstrap.css' => [
            'local' => 'assets/vendor/bootstrap/bootstrap.min.css',
            'cdn'   => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            'type'  => 'css',
        ],
        'bootstrap.js' => [
            'local' => 'assets/vendor/bootstrap/bootstrap.bundle.min.js',
            'cdn'   => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            'type'  => 'js',
        ],
        'fontawesome.css' => [
            'local' => 'assets/vendor/fontawesome/all.min.css',
            'cdn'   => 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css',
            'type'  => 'css',
        ],
        'adminlte.css' => [
            'local' => 'assets/vendor/adminlte/adminlte.min.css',
            'cdn'   => 'https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css',
            'type'  => 'css',
        ],
        'jquery.js' => [
            'local' => 'assets/vendor/jquery/jquery.min.js',
            'cdn'   => 'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js',
            'type'  => 'js',
        ],
        'datatables.css' => [
            'local' => 'assets/vendor/datatables/dataTables.bootstrap5.min.css',
            'cdn'   => 'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css',
            'type'  => 'css',
        ],
        'datatables.js' => [
            'local' => 'assets/vendor/datatables/jquery.dataTables.min.js',
            'cdn'   => 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
            'type'  => 'js',
        ],
        'sweetalert2.js' => [
            'local' => 'assets/vendor/sweetalert2/sweetalert2.all.min.js',
            'cdn'   => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.all.min.js',
            'type'  => 'js',
        ],
        'chartjs.js' => [
            'local' => 'assets/vendor/chartjs/chart.umd.min.js',
            'cdn'   => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            'type'  => 'js',
        ],
    ],

    /*
     * First-party assets, always present.
     */
    'app' => [
        'css' => ['assets/css/app.css', 'assets/css/theme.css', 'assets/css/print.css'],
        'js'  => [
            'assets/js/core.js',
            'assets/js/http.js',
            'assets/js/ui.js',
            'assets/js/table.js',
            'assets/js/chart.js',
            'assets/js/forms.js',
            'assets/js/notifications.js',
            'assets/js/app.js',
        ],
    ],
];
