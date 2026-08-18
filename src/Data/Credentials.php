<?php

namespace Nosh\OmniTax\Data;

/**
 * A resolved connection for one business to one authority: which authority,
 * sandbox vs production, the security token, and the seller identity.
 */
class Credentials
{
    public function __construct(
        public string $authority,
        public ?string $token = null,
        public bool $sandbox = true,
        public ?Seller $seller = null,
        public ?string $tenantId = null,
    ) {
    }

    public function hasToken(): bool
    {
        return ! empty($this->token);
    }

    public static function fromArray(array $d): self
    {
        return new self(
            authority: $d['authority'],
            token: $d['token'] ?? null,
            sandbox: (bool) ($d['sandbox'] ?? true),
            seller: isset($d['seller']) ? Seller::fromArray($d['seller']) : Seller::fromArray($d),
            tenantId: $d['tenant_id'] ?? $d['tenantId'] ?? null,
        );
    }
}
