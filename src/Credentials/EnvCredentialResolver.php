<?php

namespace Nosh\OmniTax\Credentials;

use Nosh\OmniTax\Contracts\CredentialResolver;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Seller;

/**
 * Single-business resolver: reads the token + seller from config/.env.
 */
class EnvCredentialResolver implements CredentialResolver
{
    public function __construct(protected array $config)
    {
    }

    public function resolve(mixed $tenant = null, ?string $authority = null): ?Credentials
    {
        $authority ??= $this->config['default'] ?? 'fbr';
        $authConfig = $this->config['authorities'][$authority] ?? [];

        return new Credentials(
            authority: $authority,
            token: $authConfig['token'] ?? null,
            sandbox: (bool) ($this->config['sandbox'] ?? true),
            seller: Seller::fromArray($this->config['seller'] ?? []),
            tenantId: null,
        );
    }
}
