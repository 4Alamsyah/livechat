<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The widget in public/js/widget.js is embedded on third-party sites and
    | calls the /api/widget/* endpoints from whatever origin embeds it, so
    | those paths must allow cross-origin requests from any domain.
    |
    */

    'paths' => ['api/*', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
