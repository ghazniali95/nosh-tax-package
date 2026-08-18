<?php

namespace Nosh\OmniTax\Contracts;

use Nosh\OmniTax\Data\Credentials;

/**
 * Resolves which authority + token + seller identity a given business uses.
 * Implementations: env (single business), database (multi-tenant), callback.
 */
interface CredentialResolver
{
    /**
     * @param  mixed  $tenant  a tenant id, model, or null for the default/env business
     */
    public function resolve(mixed $tenant = null, ?string $authority = null): ?Credentials;
}
