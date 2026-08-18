<?php

namespace Nosh\OmniTax\Data;

/**
 * The customer on an invoice. For a walk-in / retail customer the buyer is
 * "Unregistered" and NTN/CNIC may be omitted.
 */
class Buyer
{
    public const REGISTERED = 'Registered';
    public const UNREGISTERED = 'Unregistered';

    public function __construct(
        public ?string $ntncnic = null,
        public ?string $name = null,
        public ?string $province = null,
        public ?string $address = null,
        public string $registrationType = self::UNREGISTERED,
    ) {
    }

    /** A walk-in / retail customer: unregistered, no NTN required. */
    public static function walkIn(?string $province = null): self
    {
        return new self(
            ntncnic: null,
            name: 'Walk-in Customer',
            province: $province,
            registrationType: self::UNREGISTERED,
        );
    }

    public static function fromArray(array $data): self
    {
        $ntn = $data['ntncnic'] ?? $data['ntn'] ?? null;

        return new self(
            $ntn,
            $data['name'] ?? null,
            $data['province'] ?? null,
            $data['address'] ?? null,
            $data['registrationType'] ?? ($ntn ? self::REGISTERED : self::UNREGISTERED),
        );
    }

    public function isRegistered(): bool
    {
        return $this->registrationType === self::REGISTERED;
    }

    public function toArray(): array
    {
        return [
            'ntncnic'          => $this->ntncnic,
            'name'             => $this->name,
            'province'         => $this->province,
            'address'          => $this->address,
            'registrationType' => $this->registrationType,
        ];
    }
}
