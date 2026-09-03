<?php

declare(strict_types=1);

namespace HelloJade\Intake\Tests;

use HelloJade\Intake\Client;
use HelloJade\Intake\Exception\ApiException;
use HelloJade\Intake\Exception\RateLimitedException;
use HelloJade\Intake\Exception\TransportException;
use HelloJade\Intake\Exception\UnauthorizedException;
use HelloJade\Intake\Exception\ValidationException;
use HelloJade\Intake\RetryPolicy;

final class ClientTest extends ClientTestCase
{
    // --- construction -------------------------------------------------------

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(apiKey: '  ');
    }

    public function testRejectsANonHttpBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(apiKey: 'k', baseUrl: 'ftp://x');
    }

    public function testDefaults(): void
    {
        $c = new Client(apiKey: 'k');
        self::assertSame('https://intake.hellojade.ai', $c->getBaseUrl());
        self::assertSame(20.0, $c->getTimeout());
        self::assertMatchesRegularExpression('~^hellojade-intake-php/\d~', $c->getUserAgent());
        self::assertSame(5, $c->getRetryPolicy()->maxAttempts);
    }

    public function testDebugOutputNeverShowsTheKey(): void
    {
        $dump = print_r($this->client, true);
        self::assertStringNotContainsString(self::TEST_KEY, $dump);
        ob_start();
        var_dump($this->client);
        self::assertStringNotContainsString(self::TEST_KEY, (string) ob_get_clean());
    }

    // --- submitLead: success ------------------------------------------------

    public function test202ReturnsAcceptedAndSendsTheRightHeadersAndBody(): void
    {
        $this->stub->enqueue(202, $this->acceptedBody());
        $res = $this->client->submitLead($this->sampleLead(), 'acme-leads:A-99812', 'acme/req/1');

        self::assertTrue($res->isAccepted());
        self::assertFalse($res->isDuplicate());
        self::assertSame(202, $res->httpStatus);
        self::assertSame('evt_0198f2c1a4b00000a3d19f4c2b7e', $res->eventId);
        self::assertSame('acme-leads', $res->source);
        self::assertSame([], $res->flags);
        self::assertSame('acme/req/1', $res->requestId);
        self::assertSame('2026-08-21T14:03:22Z', $res->receivedAt);

        $req = $this->stub->request(0);
        self::assertSame('POST', $req['method']);
        self::assertSame('/v1/intake', $req['path']);
        self::assertSame(self::TEST_KEY, $req['headers']['x-api-key']);
        self::assertSame('acme-leads:A-99812', $req['headers']['idempotency-key']);
        self::assertSame('acme/req/1', $req['headers']['x-request-id']);
        self::assertSame('application/json', $req['headers']['content-type']);
        self::assertMatchesRegularExpression('~^hellojade-intake-php/~', $req['headers']['user-agent']);
        $body = json_decode($req['body'], true);
        self::assertSame('Dana', $body['first_name']);
        self::assertSame('(630) 555-0142', $body['phone']);
        self::assertSame('XZ-1', $body['partner_job_id']);
        self::assertSame(25000, $body['budget']);
        self::assertArrayNotHasKey('source', $body);
        self::assertArrayNotHasKey('extra', $body);
        self::assertArrayNotHasKey('cost', $body);
    }

    public function test200DuplicateIsSuccessWithTheOriginalEventId(): void
    {
        $this->stub->enqueue(200, $this->acceptedBody('duplicate'));
        $res = $this->client->submitLead($this->sampleLead(), 'acme-leads:A-99812');
        self::assertTrue($res->isDuplicate());
        self::assertSame(200, $res->httpStatus);
        self::assertSame('evt_0198f2c1a4b00000a3d19f4c2b7e', $res->eventId);
    }

    public function testFlagsAreReturnedNotThrown(): void
    {
        $this->stub->enqueue(202, $this->acceptedBody('accepted', ['phone_unnormalized', 'extra_fields_preserved']));
        $res = $this->client->submitLead($this->sampleLead(), 'acme:1');
        self::assertSame(['phone_unnormalized', 'extra_fields_preserved'], $res->flags);
        self::assertSame([], $this->sleeper->calls);
    }

    public function testAcceptsAPlainArray(): void
    {
        $this->stub->enqueue(202, $this->acceptedBody());
        $this->client->submitLead(['first_name' => 'D', 'last_name' => 'W', 'phone' => '1'], 'acme:1');
        self::assertSame('D', json_decode($this->stub->request(0)['body'], true)['first_name']);
    }

    public function testEmptyLeadArrayIsSentAsAJsonObject(): void
    {
        $this->stub->enqueue(422, ['error' => 'validation_failed', 'fields' => ['phone' => 'required']]);
        try {
            $this->client->submitLead([], 'acme:1');
            self::fail('expected ValidationException');
        } catch (ValidationException) {
        }
        self::assertSame('{}', $this->stub->request(0)['body']);
    }

    public function testGeneratesARequestIdWhenNoneIsGiven(): void
    {
        $this->stub->enqueue(202, $this->acceptedBody());
        $res = $this->client->submitLead($this->sampleLead(), 'acme:1');
        $rid = $this->stub->request(0)['headers']['x-request-id'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $rid);
        self::assertSame($rid, $res->requestId);
    }

    public function testIdempotencyKeyIsRequired(): void
    {
        try {
            $this->client->submitLead($this->sampleLead(), '');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        try {
            $this->client->submitLead($this->sampleLead(), str_repeat('x', 201));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $this->stub->requests());
    }

    // --- submitLead: 4xx are never retried ----------------------------------

    public function test400ThrowsApiExceptionWithoutRetry(): void
    {
        $this->stub->enqueue(400, ['error' => 'invalid_json', 'request_id' => '9f2c1a4b']);
        try {
            $this->client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame('invalid_json', $e->apiCode);
            self::assertSame('9f2c1a4b', $e->requestId);
            self::assertFalse($e->isRetryable());
        }
        self::assertCount(1, $this->stub->requests());
        self::assertSame([], $this->sleeper->calls);
    }

    public function test401ThrowsUnauthorizedAndNeverLeaksTheKey(): void
    {
        $this->stub->enqueue(401, ['error' => 'unauthorized', 'request_id' => '9f2c1a4b']);
        try {
            $this->client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            self::assertInstanceOf(ApiException::class, $e);
            self::assertSame(401, $e->status);
            self::assertStringNotContainsString(self::TEST_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::TEST_KEY, (string) $e);
        }
        self::assertCount(1, $this->stub->requests());
    }

    public function test413ThrowsApiExceptionWithRequestIdFromTheHeader(): void
    {
        $this->stub->enqueue(413, ['error' => 'body_too_large']);
        try {
            $this->client->submitLead($this->sampleLead(), 'acme:1', 'r-413');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(413, $e->status);
            self::assertSame('body_too_large', $e->apiCode);
            self::assertSame('r-413', $e->requestId);
        }
        self::assertCount(1, $this->stub->requests());
    }

    public function test422ThrowsValidationExceptionWithEveryField(): void
    {
        $this->stub->enqueue(422, [
            'error' => 'validation_failed', 'request_id' => '9f2c1a4b',
            'fields' => ['first_name' => 'required', 'last_name' => 'required', 'phone' => 'required'],
        ]);
        try {
            $this->client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('validation_failed', $e->apiCode);
            self::assertSame(['first_name' => 'required', 'last_name' => 'required', 'phone' => 'required'], $e->fields);
            self::assertStringContainsString('phone:required', $e->getMessage());
        }
        self::assertCount(1, $this->stub->requests());
        self::assertSame([], $this->sleeper->calls);
    }

    // --- 429 -----------------------------------------------------------------

    public function test429WaitsRetryAfterAndDoesNotConsumeAnAttempt(): void
    {
        $this->stub->enqueue(429, ['error' => 'rate_limited'], ['Retry-After' => '3']);
        $this->stub->enqueue(429, ['error' => 'rate_limited'], ['Retry-After' => '1']);
        $this->stub->enqueue(202, $this->acceptedBody());
        $res = $this->client->submitLead($this->sampleLead(), 'acme:1');
        self::assertTrue($res->isAccepted());
        self::assertCount(3, $this->stub->requests());
        // first wait: max(Retry-After 3, backoff(1)=1) = 3; second: max(1, backoff(2)=2) = 2
        self::assertSame([3.0, 2.0], $this->sleeper->calls);
        $keys = array_unique(array_map(static fn ($r) => $r['headers']['idempotency-key'], $this->stub->requests()));
        self::assertSame(['acme:1'], array_values($keys));
    }

    public function test429WithoutRetryAfterDefaultsToOneSecondFloor(): void
    {
        $this->stub->enqueue(429, ['error' => 'rate_limited']);
        $this->stub->enqueue(202, $this->acceptedBody());
        $this->client->submitLead($this->sampleLead(), 'acme:1');
        self::assertSame([1.0], $this->sleeper->calls);
    }

    public function test429ThrowsRateLimitedOnceTheWaitBudgetIsSpent(): void
    {
        $client = $this->clientWith(new RetryPolicy(maxRateLimitWaits: 2, jitter: 0.0));
        for ($i = 0; $i < 3; $i++) {
            $this->stub->enqueue(429, ['error' => 'rate_limited'], ['Retry-After' => '5']);
        }
        try {
            $client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(429, $e->status);
            self::assertSame(5, $e->retryAfter);
        }
        self::assertCount(3, $this->stub->requests());
        self::assertCount(2, $this->sleeper->calls);
    }

    // --- 5xx and transport errors are retried with backoff -------------------

    public function test503RetriesWithGrowingBackoffThenSucceeds(): void
    {
        $this->stub->enqueue(503, ['error' => 'not_accepting']);
        $this->stub->enqueue(503, ['error' => 'not_accepting']);
        $this->stub->enqueue(202, $this->acceptedBody());
        $res = $this->client->submitLead($this->sampleLead(), 'acme:1');
        self::assertTrue($res->isAccepted());
        self::assertCount(3, $this->stub->requests());
        self::assertSame([1.0, 2.0], $this->sleeper->calls);
    }

    public function test503ThrowsAfterMaxAttempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->stub->enqueue(503, ['error' => 'not_accepting']);
        }
        try {
            $this->client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
            self::assertSame('not_accepting', $e->apiCode);
            self::assertTrue($e->isRetryable());
            self::assertSame(5, $e->attempts);
        }
        self::assertCount(5, $this->stub->requests());
        self::assertSame([1.0, 2.0, 4.0, 8.0], $this->sleeper->calls);
    }

    public function testBackoffIsCappedAtMaxDelay(): void
    {
        $client = $this->clientWith(new RetryPolicy(maxAttempts: 8, jitter: 0.0));
        for ($i = 0; $i < 8; $i++) {
            $this->stub->enqueue(500, ['error' => 'boom']);
        }
        try {
            $client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected ApiException');
        } catch (ApiException) {
        }
        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0], $this->sleeper->calls);
    }

    public function testConnectionRefusedIsRetriedThenThrowsTransportException(): void
    {
        $port = StubServer::freePort();
        $client = $this->clientWith(new RetryPolicy(maxAttempts: 3, jitter: 0.0), "http://127.0.0.1:{$port}");
        try {
            $client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertSame(3, $e->attempts);
            self::assertStringNotContainsString(self::TEST_KEY, $e->getMessage());
            self::assertNotNull($e->requestId);
        }
        self::assertSame([1.0, 2.0], $this->sleeper->calls);
    }

    public function testTimeoutIsRetriedWithTheSameIdempotencyKey(): void
    {
        $client = $this->clientWith(new RetryPolicy(jitter: 0.0), timeout: 0.3);
        $this->stub->enqueue(202, $this->acceptedBody(), delay: 1.2);
        $this->stub->enqueue(202, $this->acceptedBody());
        $res = $client->submitLead($this->sampleLead(), 'acme:1');
        self::assertTrue($res->isAccepted());
        self::assertSame([1.0], $this->sleeper->calls);
        $keys = array_map(static fn ($r) => $r['headers']['idempotency-key'], $this->stub->requests());
        self::assertSame(['acme:1', 'acme:1'], $keys);
    }

    public function testNoRetryPolicyMakesExactlyOneAttempt(): void
    {
        $client = $this->clientWith(RetryPolicy::none());
        $this->stub->enqueue(503, ['error' => 'not_accepting']);
        try {
            $client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected ApiException');
        } catch (ApiException) {
        }
        self::assertCount(1, $this->stub->requests());
        self::assertSame([], $this->sleeper->calls);
    }

    // --- checkKey ------------------------------------------------------------

    public function testCheckKeyTrueOn422AndStoresNothing(): void
    {
        $this->stub->enqueue(422, [
            'error' => 'validation_failed', 'request_id' => 'x',
            'fields' => ['first_name' => 'required', 'last_name' => 'required', 'phone' => 'required'],
        ]);
        self::assertTrue($this->client->checkKey());
        $req = $this->stub->request(0);
        self::assertSame('{}', $req['body']);
        self::assertSame(self::TEST_KEY, $req['headers']['x-api-key']);
        self::assertArrayNotHasKey('idempotency-key', $req['headers']);
    }

    public function testCheckKeyFalseOn401(): void
    {
        $this->stub->enqueue(401, ['error' => 'unauthorized', 'request_id' => 'x']);
        self::assertFalse($this->client->checkKey());
    }

    public function testCheckKeyWaitsOutA429(): void
    {
        $this->stub->enqueue(429, ['error' => 'rate_limited'], ['Retry-After' => '1']);
        $this->stub->enqueue(422, ['error' => 'validation_failed', 'fields' => ['phone' => 'required']]);
        self::assertTrue($this->client->checkKey());
        self::assertSame([1.0], $this->sleeper->calls);
    }

    public function testCheckKeyThrowsOnAnythingElse(): void
    {
        $this->stub->enqueue(202, $this->acceptedBody());
        $this->expectException(ApiException::class);
        $this->client->checkKey();
    }

    // --- vocabulary / health -------------------------------------------------

    public function testVocabularyIsUnauthenticatedAndTyped(): void
    {
        $this->stub->enqueue(200, [
            'project_area' => [['area' => 'roof', 'status' => 'confirmed'], ['area' => 'solar', 'status' => 'proposed']],
            'project_service' => ['replacement', 'repair', 'remodel', 'maintain'],
            'required' => ['first_name', 'last_name', 'phone'],
        ]);
        $v = $this->client->vocabulary();
        self::assertSame(['roof', 'solar'], $v->areas());
        self::assertTrue($v->hasArea('solar'));
        self::assertSame('proposed', $v->projectArea[1]->status);
        self::assertSame(['replacement', 'repair', 'remodel', 'maintain'], $v->projectService);
        self::assertSame(['first_name', 'last_name', 'phone'], $v->required);
        $req = $this->stub->request(0);
        self::assertSame('GET', $req['method']);
        self::assertSame('/v1/vocabulary', $req['path']);
        self::assertArrayNotHasKey('x-api-key', $req['headers']);
    }

    public function testVocabulary503IsRetriedThenThrown(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->stub->enqueue(503, ['error' => 'vocabulary_unavailable']);
        }
        try {
            $this->client->vocabulary();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertCount(5, $this->stub->requests());
    }

    public function testHealth200(): void
    {
        $this->stub->enqueue(200, ['ok' => true, 'store_writable' => true, 'pending' => 0, 'dead' => 0, 'oldest_pending_age_s' => null]);
        $h = $this->client->health();
        self::assertTrue($h->ok);
        self::assertTrue($h->storeWritable);
        self::assertSame(0, $h->pending);
        self::assertNull($h->oldestPendingAgeS);
        self::assertSame(200, $h->httpStatus);
        self::assertArrayNotHasKey('x-api-key', $this->stub->request(0)['headers']);
    }

    public function testHealth503IsAReportNotAnException(): void
    {
        $this->stub->enqueue(503, ['ok' => false, 'store_writable' => false, 'pending' => 3, 'dead' => 1, 'oldest_pending_age_s' => 120]);
        $h = $this->client->health();
        self::assertFalse($h->ok);
        self::assertFalse($h->storeWritable);
        self::assertSame(120, $h->oldestPendingAgeS);
        self::assertSame(503, $h->httpStatus);
        self::assertCount(1, $this->stub->requests());
        self::assertSame([], $this->sleeper->calls);
    }

    // --- non-JSON bodies -----------------------------------------------------

    public function testNonJsonErrorBodyStillYieldsAnApiException(): void
    {
        $this->stub->enqueue(502, '<html>bad gateway</html>');
        $this->stub->enqueue(202, $this->acceptedBody());
        $res = $this->client->submitLead($this->sampleLead(), 'acme:1');
        self::assertTrue($res->isAccepted());

        $client = $this->clientWith(RetryPolicy::none());
        $this->stub->enqueue(502, '<html>bad gateway</html>');
        try {
            $client->submitLead($this->sampleLead(), 'acme:1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('http_502', $e->apiCode);
            self::assertStringContainsString('bad gateway', (string) $e->body);
        }
    }
}
