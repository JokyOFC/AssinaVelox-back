<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        // Os testes desligam (phpunit.xml). Com o Vite de desenvolvimento no ar
        // (`composer run dev`), o gateway mandaria cada página da suíte para o SSR
        // do Vite em 127.0.0.1:5173 — requisição fora do Http::fake e resposta vazia.
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),
        'url' => 'http://127.0.0.1:13714',
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        // Desativado: o front (resources/js/pages) é construído em paralelo; os testes de
        // backend validam props e status, não a existência do componente.
        'ensure_pages_exist' => (bool) env('INERTIA_ENSURE_PAGES_EXIST', false),

    ],

];
