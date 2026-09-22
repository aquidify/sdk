<?php

return [
    // Server-side only. Without a key the client cannot be resolved.
    'key' => env('AQUIDIFY_API_KEY'),

    'url' => env('AQUIDIFY_URL', 'https://api.aquidify.com'),

    // Seconds. Keep this short in a web request and fall back when it runs out:
    // the answer is still computed and cached, so the next request is instant.
    'timeout' => (int) env('AQUIDIFY_TIMEOUT', 8),

    // Pin versions in production so the response shape never changes under you.
    // null = the API's active version.
    'parser_version' => env('AQUIDIFY_PARSER_VERSION', '1.0.0'),
    'schema_version' => env('AQUIDIFY_SCHEMA_VERSION', '1.0.0'),
];
