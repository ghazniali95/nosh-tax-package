<?php

namespace Nosh\OmniTax\Credentials;

use Nosh\OmniTax\Contracts\CredentialResolver;
use Nosh\OmniTax\Data\Credentials;

/**
 * Resolve credentials from the host application via a closure
 * (OmniTax::resolveCredentialsUsing(fn ($tenantId) => ...)).
 * The closure may return a Credentials, a FiscalCredential model, or an array.
 */
class CallbackCredentialResolver implements CredentialResolver
{
    /** @var \Closure */
    protected $callback;

    public function __construct(\Closure $callback)
    {
        $this->callback = $callback;
    }

    public function resolve(mixed $tenant = null, ?string $authority = null): ?Credentials
    {
        $result = ($this->callback)($tenant, $authority);

        if ($result === null) {
            return null;
        }
        if ($result instanceof Credentials) {
            return $result;
        }
        if (is_array($result)) {
            return Credentials::fromArray($result);
        }
        if (is_object($result)) {
            return Credentials::fromArray([
                'authority'      => $result->authority ?? $authority,
                'token'          => $result->token ?? null,
                'sandbox'        => $result->sandbox ?? true,
                'ntncnic'        => $result->seller_ntncnic ?? null,
                'name'           => $result->seller_name ?? null,
                'province'       => $result->seller_province ?? null,
                'address'        => $result->seller_address ?? null,
                'tenant_id'      => $result->tenant_id ?? null,
            ]);
        }

        return null;
    }
}
