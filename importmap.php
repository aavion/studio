<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    'alpinejs' => ['version' => '3.15.12'],
    'apexcharts' => ['version' => '5.12.0'],
    'codemirror' => ['version' => '6.0.2'],
    '@codemirror/view' => ['version' => '6.43.0'],
    '@codemirror/state' => ['version' => '6.6.0'],
    '@codemirror/language' => ['version' => '6.12.3'],
    '@codemirror/commands' => ['version' => '6.10.3'],
    '@codemirror/search' => ['version' => '6.7.0'],
    '@codemirror/autocomplete' => ['version' => '6.20.2'],
    '@codemirror/lint' => ['version' => '6.9.6'],
    'style-mod' => ['version' => '4.1.3'],
    'w3c-keyname' => ['version' => '2.2.8'],
    'crelt' => ['version' => '1.0.6'],
    '@marijn/find-cluster-break' => ['version' => '1.0.2'],
    '@lezer/common' => ['version' => '1.5.2'],
    '@lezer/highlight' => ['version' => '1.2.3'],
    '@codemirror/highlight' => ['version' => '0.19.8'],
    '@codemirror/rangeset' => ['version' => '0.19.9'],
    '@codemirror/text' => ['version' => '0.19.6'],
    '@codemirror/lang-css' => ['version' => '6.3.1'],
    '@lezer/css' => ['version' => '1.3.3'],
    '@lezer/lr' => ['version' => '1.4.10'],
    '@codemirror/lang-html' => ['version' => '6.4.11'],
    '@lezer/html' => ['version' => '1.3.13'],
    '@codemirror/lang-javascript' => ['version' => '6.2.5'],
    '@lezer/javascript' => ['version' => '1.5.4'],
    '@codemirror/lang-php' => ['version' => '6.0.2'],
    '@lezer/php' => ['version' => '1.0.5'],
    '@codemirror/lang-json' => ['version' => '6.0.2'],
    '@lezer/json' => ['version' => '1.0.3'],
    '@codemirror/lang-markdown' => ['version' => '6.5.0'],
    '@lezer/markdown' => ['version' => '1.6.3'],
    'tom-select' => ['version' => '2.6.1'],
    '@orchidjs/sifter' => ['version' => '1.1.0'],
    '@orchidjs/unicode-variants' => ['version' => '1.1.2'],
    'tom-select/dist/css/tom-select.default.min.css' => ['version' => '2.6.1', 'type' => 'css'],
    'tom-select/dist/css/tom-select.default.css' => ['version' => '2.6.1', 'type' => 'css'],
    'tom-select/dist/css/tom-select.bootstrap4.css' => ['version' => '2.6.1', 'type' => 'css'],
    'tom-select/dist/css/tom-select.bootstrap5.css' => ['version' => '2.6.1', 'type' => 'css'],
    'chart.js' => ['version' => '4.5.1'],
    '@kurkle/color' => ['version' => '0.3.4'],
    'cropperjs' => ['version' => '1.6.2'],
    'cropperjs/dist/cropper.min.css' => ['version' => '1.6.2', 'type' => 'css'],
    '@symfony/ux-live-component' => ['path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js'],
    '@hotwired/hotwire-native-bridge' => ['version' => '1.2.2'],
    'react' => ['version' => '19.2.7'],
    'react-dom/client' => ['version' => '19.2.7'],
    'scheduler' => ['version' => '0.27.0'],
    'react-dom' => ['version' => '19.2.7'],
    '@symfony/ux-react' => ['path' => './vendor/symfony/ux-react/assets/dist/loader.js'],
    'intl-messageformat' => ['version' => '10.7.18'],
    'tslib' => ['version' => '2.8.1'],
    '@formatjs/fast-memoize' => ['version' => '2.2.7'],
    '@formatjs/icu-messageformat-parser' => ['version' => '2.11.4'],
    '@formatjs/icu-skeleton-parser' => ['version' => '1.8.16'],
    '@symfony/ux-translator' => ['path' => './vendor/symfony/ux-translator/assets/dist/translator_controller.js'],
    'vue' => ['version' => '3.5.38', 'package_specifier' => 'vue/dist/vue.esm-bundler.js'],
    '@vue/runtime-dom' => ['version' => '3.5.38'],
    '@vue/compiler-dom' => ['version' => '3.5.38'],
    '@vue/shared' => ['version' => '3.5.38'],
    '@vue/runtime-core' => ['version' => '3.5.38'],
    '@vue/compiler-core' => ['version' => '3.5.38'],
    '@vue/reactivity' => ['version' => '3.5.38'],
    '@symfony/ux-vue' => ['path' => './vendor/symfony/ux-vue/assets/dist/loader.js'],
    'leaflet' => ['version' => '1.9.4'],
    'leaflet/dist/leaflet.min.css' => ['version' => '1.9.4', 'type' => 'css'],
    '@symfony/ux-leaflet-map' => ['path' => './vendor/symfony/ux-leaflet-map/assets/dist/map_controller.js'],
];
