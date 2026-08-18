<?php

namespace Nosh\OmniTax\Events;

/** Fires when a tenant tried to submit with no fiscal credentials. */
class CredentialsMissing
{
    public function __construct(
        public mixed $tenant,
        public ?string $authority = null,
    ) {
    }
}
