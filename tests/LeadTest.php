<?php

declare(strict_types=1);

namespace HelloJade\Intake\Tests;

use HelloJade\Intake\Lead;
use PHPUnit\Framework\TestCase;

final class LeadTest extends TestCase
{
    public function testMinimalLeadSerializesOnlyRequiredFields(): void
    {
        $lead = new Lead('Dana', 'Whitfield', '6305550142');
        self::assertSame(['first_name' => 'Dana', 'last_name' => 'Whitfield', 'phone' => '6305550142'], $lead->toArray());
    }

    public function testNullFieldsAreOmittedNotSentAsNull(): void
    {
        $lead = new Lead('Dana', 'Whitfield', '1', email: null, cost: null);
        self::assertArrayNotHasKey('email', $lead->toArray());
        self::assertArrayNotHasKey('cost', $lead->toArray());
    }

    public function testExtraFieldsAreSentAtTheTopLevel(): void
    {
        $lead = new Lead('Dana', 'W', '1', extra: ['partner_job_id' => 'XZ-1', 'budget' => 25000]);
        $h = json_decode(json_encode($lead), true);
        self::assertSame('XZ-1', $h['partner_job_id']);
        self::assertSame(25000, $h['budget']);
        self::assertArrayNotHasKey('extra', $h);
    }

    public function testExtraCollidingWithAModeledFieldIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Lead('D', 'W', '1', extra: ['phone' => '2']);
    }

    public function testSourceIsReserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Lead('D', 'W', '1', extra: ['source' => 'x']);
    }

    public function testExtraKeyIsReserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Lead('D', 'W', '1', extra: ['extra' => []]);
    }

    public function testFromArrayRoutesUnmodeledKeysToExtra(): void
    {
        $lead = Lead::fromArray(['first_name' => 'D', 'last_name' => 'W', 'phone' => '1', 'cost' => 12.5, 'crew' => 'north']);
        self::assertSame(12.5, $lead->cost);
        self::assertSame(['crew' => 'north'], $lead->getExtra());
        self::assertSame('north', $lead->toArray()['crew']);
    }

    public function testFromArrayRequiresTheRequiredFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Lead::fromArray(['first_name' => 'D', 'phone' => '1']);
    }

    public function testProjectServicesEnum(): void
    {
        self::assertSame(['replacement', 'repair', 'remodel', 'maintain'], Lead::PROJECT_SERVICES);
    }
}
