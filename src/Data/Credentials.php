<?php

namespace Nosh\OmniTax\Data;

/**
 * A resolved connection for one business to one authority: which authority,
 * sandbox vs production, the security token, and the seller identity.
 */
class Credentials
{
    /**
     * @param ?string $posId    SRB/KPRA: the registered POS ID.
     * @param ?string $posUser  SRB cloud-only: gateway username (sent in the body).
     * @param ?string $posPass  SRB cloud-only: gateway password (sent in the body).
     * @param ?string $mode     SRB/KPRA: 'cloud' (website) or 'offline' (desktop utility/connector).
     * @param ?string $apiKey   KPRA-only: the secret "key" sent with pos_id in the request body.
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
        public ?string $apiKey = null,
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
            apiKey: $d['api_key'] ?? $d['apiKey'] ?? $d['key'] ?? null,
        );
    }
}
