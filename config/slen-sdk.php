<?php

declare(strict_types=1);

return [
    'api_key' => env('SLEN_API_KEY'),
    // Seconds, fractions allowed; zero, a negative number or text is refused when the SDK is built.
    'timeout' => (float) env('SLEN_TIMEOUT', 65),
    'connect_timeout' => (float) env('SLEN_CONNECT_TIMEOUT', 5),
    'retries' => (int) env('SLEN_RETRIES', 2),
    // A channel of config/logging.php for the journal of the SDK; null is the logger of the application.
    'log_channel' => env('SLEN_LOG_CHANNEL'),
    // `null` or `log`: the spans of runs written as `slen.span` to the logger of the journal.
    'spans' => env('SLEN_SPANS', 'null'),
    'span_level' => env('SLEN_SPAN_LEVEL', 'info'),
    'webhook_secret' => env('SLEN_WEBHOOK_SECRET'),
    // Seconds a webhook signature may be from now.
    'webhook_tolerance' => (int) env('SLEN_WEBHOOK_TOLERANCE', 300),
];
