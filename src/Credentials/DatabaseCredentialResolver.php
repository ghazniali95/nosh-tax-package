<?php

namespace Nosh\OmniTax\Credentials;

use Nosh\OmniTax\Contracts\CredentialResolver;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Seller;
use Nosh\OmniTax\Models\FiscalCredential;

/**
 * Multi-tenant resolver: looks up a FiscalCredential row per tenant.
 * A business with no row simply has fiscal invoicing dormant.
 */
class DatabaseCredentialResolver implements CredentialResolver
{
    public function __construct(protected array $config)
    {
    }

    public function resolve(mixed $tenant = null, ?string $authority = null): ?Credentials
    {
        $tenantId = $this->tenantId($tenant);
        if ($tenantId === null) {
            return null;
        }

        $query = FiscalCredential::query()->where('tenant_id', $tenantId);
        if ($authority) {
            $query->where('authority', $authority);
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        return new Credentials(
            authority: $row->authority,
            token: $row->token,        // decrypted by the cast
            sandbox: (bool) $row->sandbox,
            seller: new Seller(
                $row->seller_ntncnic,
                $row->seller_name,
                $row->seller_province,
                $row->seller_address,
            ),
            tenantId: $tenantId,
        );
    }

    protected function tenantId(mixed $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }
        if (is_object($tenant)) {
            return (string) ($tenant->getKey() ?? $tenant->id ?? null);
        }

        return (string) $tenant;
    }
}
