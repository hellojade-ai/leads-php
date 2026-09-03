<?php

declare(strict_types=1);

namespace HelloJade\Intake;

/**
 * GET /v1/vocabulary — the live project_area terms, the closed
 * project_service enum, and the fields the validator currently requires.
 */
final class Vocabulary
{
    /**
     * @param list<VocabularyTerm> $projectArea
     * @param list<string>         $projectService
     * @param list<string>         $required
     */
    public function __construct(
        public readonly array $projectArea,
        public readonly array $projectService,
        public readonly array $required,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $terms = [];
        foreach ((array) ($body['project_area'] ?? []) as $t) {
            if (is_array($t)) {
                $terms[] = new VocabularyTerm((string) ($t['area'] ?? ''), (string) ($t['status'] ?? ''));
            }
        }

        return new self(
            projectArea: $terms,
            projectService: array_values(array_map('strval', (array) ($body['project_service'] ?? []))),
            required: array_values(array_map('strval', (array) ($body['required'] ?? []))),
        );
    }

    /**
     * @return list<string>
     */
    public function areas(): array
    {
        return array_map(static fn (VocabularyTerm $t): string => $t->area, $this->projectArea);
    }

    public function hasArea(string $term): bool
    {
        return in_array($term, $this->areas(), true);
    }
}
