<?php

// Live smoke test, no model calls (free):
//   AQUIDIFY_API_KEY=... [AQUIDIFY_BASE_URL=...] composer test

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Aquidify\AquidifyException;
use Aquidify\Client;

if (! getenv('AQUIDIFY_API_KEY')) {
    echo "skip: set AQUIDIFY_API_KEY\n";
    exit(0);
}
$base = getenv('AQUIDIFY_BASE_URL') ?: 'https://api.aquidify.com';

// assert() is often compiled out; this is not.
function check(bool $ok, string $what): void
{
    if (! $ok) {
        fwrite(STDERR, "FAIL: $what\n");
        exit(1);
    }
}

$r = (new Client(null, $base))->interpret('hiring.candidate', 'Ljubljana', 'sl-SI');
check($r['meta']['source'] === 'deterministic', 'place alone resolves without a model');
check($r['interpretation']['intents'][0]['locations'][0]['value'] === 'Ljubljana', 'location value');
check($r['clarification'] === null, 'no clarification');

try {
    (new Client('wrong-key', $base))->interpret('hiring.candidate', 'x', 'sl');
    check(false, 'wrong key must throw');
} catch (AquidifyException $e) {
    check($e->status === 401 && $e->errorCode === 'unauthorized' && ! $e->isRetryable(), '401 unauthorized');
}

try {
    (new Client(null, $base))->interpret('no.such.domain', 'x', 'sl');
    check(false, 'unknown domain must throw');
} catch (AquidifyException $e) {
    check($e->status === 422 && $e->errorCode === 'invalid_request' && $e->requestId !== null, '422 invalid_request');
}

echo "php: ok\n";
