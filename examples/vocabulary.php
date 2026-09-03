<?php

declare(strict_types=1);

/*
 * Print the live project_area vocabulary. The endpoint is unauthenticated, so
 * no key is needed — the Client still wants one, since every other call does.
 *
 *   php examples/vocabulary.php
 */

require __DIR__ . '/_bootstrap.php';

use HelloJade\Intake\Client;
use HelloJade\Intake\Exception\IntakeException;
use HelloJade\Intake\VocabularyTerm;

$client = new Client(
    apiKey: getenv('HELLOJADE_API_KEY') ?: 'not-needed-for-vocabulary',
    baseUrl: getenv('HELLOJADE_BASE_URL') ?: Client::DEFAULT_BASE_URL,
);

try {
    $vocab = $client->vocabulary();
} catch (IntakeException $e) {
    fwrite(STDERR, 'could not fetch the vocabulary: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'required: ' . implode(', ', $vocab->required) . "\n";
echo 'project_service: ' . implode(', ', $vocab->projectService) . "\n";
echo "project_area:\n";
foreach ($vocab->projectArea as $term) {
    /* @var VocabularyTerm $term */
    echo "  {$term->area} ({$term->status})\n";
}
