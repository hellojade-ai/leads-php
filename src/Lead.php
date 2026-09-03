<?php

declare(strict_types=1);

namespace HelloJade\Intake;

/**
 * One lead, in the API's envelope. `firstName`, `lastName` and `phone` are
 * required; everything else is optional and omitted from the JSON when null.
 * Fields the API does not model go in `$extra` and are sent at the top level,
 * where the API preserves them (rule 7).
 */
final class Lead implements \JsonSerializable
{
    /** The closed project_service enum. project_area is NOT a constant on purpose — fetch it with Client::vocabulary(). */
    public const PROJECT_SERVICES = ['replacement', 'repair', 'remodel', 'maintain'];

    /** Keys a partner must never send at the top level. "source" comes from the API key (rule 6); "extra" is reserved by the API. */
    public const RESERVED_KEYS = ['source', 'extra'];

    /** @var list<string> Every modeled field, in wire spelling. */
    public const FIELDS = [
        'first_name', 'last_name', 'phone', 'email', 'street_address', 'city', 'state', 'zip', 'country',
        'project_area', 'project_service', 'project_material', 'project_details', 'external_id', 'cost',
    ];

    /** @var array<string, mixed> */
    private array $extra = [];

    /**
     * @param array<string, mixed> $extra unmodeled fields, sent at the top level
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $phone,
        public ?string $email = null,
        public ?string $streetAddress = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $zip = null,
        public ?string $country = null,
        public ?string $projectArea = null,
        public ?string $projectService = null,
        public ?string $projectMaterial = null,
        public ?string $projectDetails = null,
        public ?string $externalId = null,
        public int|float|null $cost = null,
        array $extra = [],
    ) {
        $this->setExtra($extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function setExtra(array $extra): void
    {
        $keys = array_map('strval', array_keys($extra));
        $reserved = array_values(array_intersect($keys, self::RESERVED_KEYS));
        if ($reserved !== []) {
            throw new \InvalidArgumentException(
                'reserved key(s) in extra: ' . implode(', ', $reserved)
                . ' — source comes from your API key and extra is set by the API'
            );
        }
        $collide = array_values(array_intersect($keys, self::FIELDS));
        if ($collide !== []) {
            throw new \InvalidArgumentException('extra collides with modeled field(s): ' . implode(', ', $collide));
        }
        $out = [];
        foreach ($extra as $k => $v) {
            $out[(string) $k] = $v;
        }
        $this->extra = $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * Build a Lead from a wire-shaped array (snake_case keys). Unmodeled keys
     * land in `extra`.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $extra = [];
        $modeled = [];
        foreach ($data as $k => $v) {
            $k = (string) $k;
            if (in_array($k, self::FIELDS, true)) {
                $modeled[$k] = $v;
            } else {
                $extra[$k] = $v;
            }
        }
        foreach (['first_name', 'last_name', 'phone'] as $required) {
            if (!isset($modeled[$required])) {
                throw new \InvalidArgumentException("missing required lead field: {$required}");
            }
        }

        return new self(
            firstName: (string) $modeled['first_name'],
            lastName: (string) $modeled['last_name'],
            phone: (string) $modeled['phone'],
            email: self::str($modeled, 'email'),
            streetAddress: self::str($modeled, 'street_address'),
            city: self::str($modeled, 'city'),
            state: self::str($modeled, 'state'),
            zip: self::str($modeled, 'zip'),
            country: self::str($modeled, 'country'),
            projectArea: self::str($modeled, 'project_area'),
            projectService: self::str($modeled, 'project_service'),
            projectMaterial: self::str($modeled, 'project_material'),
            projectDetails: self::str($modeled, 'project_details'),
            externalId: self::str($modeled, 'external_id'),
            cost: isset($modeled['cost']) ? $modeled['cost'] : null,
            extra: $extra,
        );
    }

    /**
     * The JSON object the API receives. Modeled fields with a null value are
     * omitted — never sent as null or as a placeholder (rule 1).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = $this->extra;
        $modeled = [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'email' => $this->email,
            'street_address' => $this->streetAddress,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'country' => $this->country,
            'project_area' => $this->projectArea,
            'project_service' => $this->projectService,
            'project_material' => $this->projectMaterial,
            'project_details' => $this->projectDetails,
            'external_id' => $this->externalId,
            'cost' => $this->cost,
        ];
        foreach ($modeled as $k => $v) {
            if ($v !== null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key): ?string
    {
        return isset($data[$key]) ? (string) $data[$key] : null;
    }
}
