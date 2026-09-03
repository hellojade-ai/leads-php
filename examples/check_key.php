<?php

declare(strict_types=1);

/*
 * Prove your key works without creating a lead.
 *
 *   HELLOJADE_API_KEY=... php examples/check_key.php
 *
 * The API authenticates before it validates, so an empty body with a valid key
 * is rejected with 422 — and nothing is stored, delivered or emailed.
 */

require __DIR__ . '/_bootstrap.php';

use HelloJade\Intake\Client;
use HelloJade\Intake\Exception\IntakeException;

$client = new Client(
    apiKey: requireEnv('HELLOJADE_API_KEY'),
    baseUrl: getenv('HELLOJADE_BASE_URL') ?: Client::DEFAULT_BASE_URL,
);

try {
    if ($client->checkKey()) {
        echo "key is valid and active (API answered 422 to an empty body; nothing stored)\n";
        exit(0);
    }

    fwrite(STDERR, "key rejected (401): check the X-API-Key value for whitespace, then ask hellojade whether it is active\n");
    exit(1);
} catch (IntakeException $e) {
    // Anything other than 401/422 means the endpoint is not what we think it is.
    fwrite(STDERR, 'could not check the key: ' . $e->getMessage() . "\n");
    exit(2);
}
