<?php

declare(strict_types=1);

namespace HelloJade\Intake\Tests;

use HelloJade\Intake\Client;
use HelloJade\Intake\Lead;
use HelloJade\Intake\RetryPolicy;
use PHPUnit\Framework\TestCase;

abstract class ClientTestCase extends TestCase
{
    public const TEST_KEY = 'hj_test_key_do_not_log_5f3a9c';

    protected StubServer $stub;
    protected FakeSleeper $sleeper;
    protected Client $client;

    protected function setUp(): void
    {
        $this->stub = new StubServer();
        $this->sleeper = new FakeSleeper();
        $this->client = new Client(
            apiKey: self::TEST_KEY,
            baseUrl: $this->stub->url(),
            timeout: 2.0,
            retryPolicy: new RetryPolicy(jitter: 0.0),
            sleeper: $this->sleeper,
        );
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    /** @return array<string, mixed> */
    protected function acceptedBody(string $status = 'accepted', array $flags = []): array
    {
        return [
            'event_id' => 'evt_0198f2c1a4b00000a3d19f4c2b7e',
            'status' => $status,
            'received_at' => '2026-08-21T14:03:22Z',
            'source' => 'acme-leads',
            'flags' => $flags,
        ];
    }

    protected function sampleLead(): Lead
    {
        return new Lead(
            firstName: 'Dana',
            lastName: 'Whitfield',
            phone: '(630) 555-0142',
            email: 'dana.whitfield@example.com',
            city: 'Naperville',
            state: 'IL',
            zip: '60540',
            projectArea: 'roof',
            projectService: 'replacement',
            externalId: 'A-99812',
            extra: ['partner_job_id' => 'XZ-1', 'budget' => 25000],
        );
    }

    protected function clientWith(RetryPolicy $policy, ?string $baseUrl = null, float $timeout = 2.0): Client
    {
        return new Client(
            apiKey: self::TEST_KEY,
            baseUrl: $baseUrl ?? $this->stub->url(),
            timeout: $timeout,
            retryPolicy: $policy,
            sleeper: $this->sleeper,
        );
    }
}
