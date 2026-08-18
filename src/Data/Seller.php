<?php

namespace Nosh\OmniTax\Data;

/**
 * The registered business identity that appears on every invoice.
 * Authority-neutral: drivers map these to their own field names.
 */
class Seller
{
    public function __construct(
        public ?string $ntncnic = null,
        public ?string $name = null,
        public ?string $province = null,
        public ?string $address = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['ntncnic']  ?? $data['ntn'] ?? null,
            $data['name']     ?? null,
            $data['province'] ?? null,
            $data['address']  ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'ntncnic'  => $this->ntncnic,
            'name'     => $this->name,
            'province' => $this->province,
            'address'  => $this->address,
        ];
    }

    public function isComplete(): bool
    {
        return $this->ntncnic && $this->name && $this->province && $this->address;
    }
}
