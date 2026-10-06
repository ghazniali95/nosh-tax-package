<?php

namespace Nosh\OmniTax\Data;

/**
 * A resolved connection for one business to one authority: which authority,
 * sandbox vs production, the security token, and the seller identity.
 */
class Credentials
{
    /**
     * @param ?string $posId    SRB-only: the registered POS ID.
     * @param ?string $posUser  SRB cloud-only: gateway username (sent in the body).
     * @param ?string $posPass  SRB cloud-only: gateway password (sent in the body).
     * @param ?string $mode     SRB-only: 'cloud' (website) or 'offline' (desktop connector).
     */
    public function __construct(
        public string $authority,
        public ?string $token = null,
        public bool $sandbox = true,
        public ?Seller $seller = null,
        public ?string $tenantId = null,
        public ?string $posId = null,
        public ?string $posUser = null,
        public ?string $posPass = null,
        public ?string $mode = null,
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
            posId: isset($d['pos_id']) ? (string) $d['pos_id'] : (isset($d['posId']) ? (string) $d['posId'] : null),
            posUser: $d['pos_user'] ?? $d['posUser'] ?? null,
            posPass: $d['pos_pass'] ?? $d['posPass'] ?? null,
            mode: $d['mode'] ?? null,
        );
    }
}
