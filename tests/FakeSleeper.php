<?php

declare(strict_types=1);

namespace HelloJade\Intake\Tests;

/** Records every requested sleep instead of sleeping. */
final class FakeSleeper
{
    /** @var list<float> */
    public array $calls = [];

    public function __invoke(float $seconds): void
    {
        $this->calls[] = $seconds;
    }
}
