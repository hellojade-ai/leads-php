<?php

declare(strict_types=1);

/*
 * Submit one lead with the full envelope, and handle every outcome.
 *
 *   HELLOJADE_API_KEY=... php examples/submit_lead.php
 *
 * Do NOT run this against production with a real-looking name and phone unless
 * hellojade has issued you a sandbox key: a real lead reaches a real salesperson.
 */

require __DIR__ . '/_bootstrap.php';

use HelloJade\Intake\Client;
use HelloJade\Intake\Exception\ApiException;
use HelloJade\Intake\Exception\RateLimitedException;
use HelloJade\Intake\Exception\TransportException;
use HelloJade\Intake\Exception\UnauthorizedException;
use HelloJade\Intake\Exception\ValidationException;
use HelloJade\Intake\Lead;
use HelloJade\Intake\RetryPolicy;

$client = new Client(
    apiKey: requireEnv('HELLOJADE_API_KEY'),
    baseUrl: getenv('HELLOJADE_BASE_URL') ?: Client::DEFAULT_BASE_URL,
    timeout: 20.0,
    userAgent: 'acme-leads-sync/2.3',
    retryPolicy: new RetryPolicy(maxAttempts: 5),
);

// Your own record id. It becomes the Idempotency-Key (namespaced) and external_id.
$leadId = 'A-99812';

$lead = new Lead(
    firstName: 'Dana',
    lastName: 'Whitfield',
    phone: '(630) 555-0142',
    email: 'dana.whitfield@example.com',
    streetAddress: '418 N Maple St',
    city: 'Naperville',
    state: 'IL',
    zip: '60540',
    country: 'US',
    projectArea: 'roof',              // fetch the live list with $client->vocabulary()
    projectService: 'replacement',    // replacement | repair | remodel | maintain
    projectMaterial: 'asphalt shingle',
    projectDetails: 'Hail damage on the south slope, insurance claim already filed.',
    externalId: $leadId,
    cost: 555.55,                     // omit entirely if there is no charge; never 0
    extra: ['partner_job_id' => 'XZ-1'], // unmodeled fields are preserved, not rejected
);

try {
    $result = $client->submitLead($lead, "acme-leads:{$leadId}", "acme-leads/{$leadId}");

    // 202 => "accepted", 200 => "duplicate" (same event_id as the first time). Both are success.
    printf("%s: event_id=%s source=%s\n", $result->status, $result->eventId, (string) $result->source);
    if ($result->flags !== []) {
        echo 'flags (not errors): ' . implode(', ', $result->flags) . "\n";
    }
    exit(0);
} catch (ValidationException $e) {
    // 422 — every failing field at once. Fix the body; do not retry it unchanged.
    fwrite(STDERR, "validation failed (request_id={$e->requestId}): " . json_encode($e->fields) . "\n");
} catch (UnauthorizedException $e) {
    fwrite(STDERR, "unauthorized (request_id={$e->requestId}) — this is a configuration problem, not a retry\n");
} catch (RateLimitedException $e) {
    fwrite(STDERR, "rate limited for too long (last Retry-After={$e->retryAfter}s)\n");
} catch (ApiException $e) {
    // 400 / 413 / exhausted 5xx. Keep request_id — it is the handle support needs.
    fwrite(STDERR, "intake error {$e->status} {$e->apiCode} (request_id={$e->requestId})\n");
} catch (TransportException $e) {
    fwrite(STDERR, "no response after {$e->attempts} attempts: {$e->getMessage()}\n");
}

exit(1);
