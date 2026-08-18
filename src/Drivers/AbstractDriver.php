<?php

namespace Nosh\OmniTax\Drivers;

use Nosh\OmniTax\Contracts\FiscalAuthorityDriver;
use Nosh\OmniTax\Contracts\Transport;
use Nosh\OmniTax\Data\Credentials;

/**
 * Shared plumbing for authority drivers: holds the resolved credentials,
 * the config block, and the transport (real or mock).
 */
abstract class AbstractDriver implements FiscalAuthorityDriver
{
    public function __construct(
        protected Credentials $credentials,
        protected array $config,
        protected Transport $transport,
    ) {
    }

    protected function url(string $method): string
    {
        $urls = $this->config['urls'] ?? [];
        $sandbox = $this->credentials->sandbox;

        return $sandbox
            ? ($urls[$method.'_sb'] ?? $urls[$method] ?? '')
            : ($urls[$method] ?? '');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.($this->credentials->token ?? ''),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }
}
