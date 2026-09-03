<?php

declare(strict_types=1);

namespace HelloJade\Intake;

/**
 * One accepted project_area term. `$status` is "confirmed" or "proposed";
 * both are accepted by the API.
 */
final class VocabularyTerm
{
    public function __construct(
        public readonly string $area,
        public readonly string $status,
    ) {
    }
}
