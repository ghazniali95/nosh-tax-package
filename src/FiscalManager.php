<?php

namespace Nosh\OmniTax;

use Closure;
use Illuminate\Contracts\Container\Container;
use Nosh\OmniTax\Contracts\CredentialResolver;
use Nosh\OmniTax\Contracts\FiscalAuthorityDriver;
use Nosh\OmniTax\Contracts\Transport;
use Nosh\OmniTax\Credentials\CallbackCredentialResolver;
use Nosh\OmniTax\Credentials\DatabaseCredentialResolver;
use Nosh\OmniTax\Credentials\EnvCredentialResolver;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Events\CredentialsMissing;
use Nosh\OmniTax\Events\InvoiceAccepted;
use Nosh\OmniTax\Events\InvoiceRejected;
use Nosh\OmniTax\Events\InvoiceSubmitting;
use Nosh\OmniTax\Exceptions\CredentialsMissingException;
use Nosh\OmniTax\Exceptions\FiscalException;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Support\Qr\Logo;
use Nosh\OmniTax\Transport\HttpTransport;
use Nosh\OmniTax\Transport\MockTransport;

/**
 * The class behind the Fiscal facade.
 *
 * Fluent context (authority/tenant/sandbox) is applied by cloning, so:
 *   OmniTax::authority('pra')->submit($invoice)
 *   OmniTax::for($restaurant)->submit($invoice)
 *   OmniTax::sandbox()->submit($invoice)
 * never leak state between calls.
 */
class FiscalManager
{
    protected ?string $authority = null;
    protected mixed $tenant = null;
    protected ?bool $sandboxOverride = null;
    protected ?Closure $credentialCallback = null;

    public function __construct(
        protected Container $container,
        protected array $config,
    ) {
    }

    // ---- Fluent context ---------------------------------------------------

    public function authority(string $authority): static
    {
        $clone = clone $this;
        $clone->authority = $authority;

        return $clone;
    }

    public function for(mixed $tenant): static
    {
        $clone = clone $this;
        $clone->tenant = $tenant;

        return $clone;
    }

    public function sandbox(bool $on = true): static
    {
        $clone = clone $this;
        $clone->sandboxOverride = $on;

        return $clone;
    }

    public function resolveCredentialsUsing(Closure $callback): void
    {
        $this->credentialCallback = $callback;
    }

    // ---- Public actions ---------------------------------------------------

    public function validate(Invoice $invoice): FiscalResponse
    {
        return $this->run($invoice, 'validate');
    }

    public function submit(Invoice $invoice): FiscalResponse
    {
        return $this->run($invoice, 'submit');
    }

    public function logo(?string $authority = null): Logo
    {
        return new Logo($authority ?? $this->authority ?? $this->config['default']);
    }

    // ---- Reference data ---------------------------------------------------

    public function provinces(): array
    {
        return $this->driver()->reference('provinces');
    }

    public function units(): array
    {
        return $this->driver()->reference('units');
    }

    public function hsCodes(): array
    {
        return $this->driver()->reference('hsCodes');
    }

    public function taxRates(): array
    {
        return $this->driver()->reference('taxRates');
    }

    public function checkStatl(string $ntn, ?string $date = null): array
    {
        return $this->driver()->reference('statl');
    }

    // ---- Driver assembly --------------------------------------------------

    public function driver(?Credentials $credentials = null): FiscalAuthorityDriver
    {
        $credentials ??= $this->credentials();

        $authKey = $credentials->authority;
        $authConfig = $this->config['authorities'][$authKey] ?? null;

        if (! $authConfig) {
            throw new FiscalException("Unknown fiscal authority [{$authKey}].");
        }

        $driverClass = $authConfig['driver'] ?? null;
        if (! $driverClass) {
            throw new FiscalException(
                "Authority [{$authKey}] ({$authConfig['label']}) is not yet available. "
                ."Its driver is still rolling out."
            );
        }

        return new $driverClass($credentials, $authConfig, $this->transport());
    }

    public function credentials(): Credentials
    {
        $resolver = $this->credentialResolver();
        $credentials = $resolver->resolve($this->tenant, $this->authority);

        if (! $credentials) {
            event(new CredentialsMissing($this->tenant, $this->authority));
            throw new CredentialsMissingException(
                'No fiscal credentials configured'
                .($this->tenant ? ' for the given tenant.' : '.')
            );
        }

        if ($this->sandboxOverride !== null) {
            $credentials->sandbox = $this->sandboxOverride;
        }

        return $credentials;
    }

    protected function credentialResolver(): CredentialResolver
    {
        if ($this->credentialCallback) {
            return new CallbackCredentialResolver($this->credentialCallback);
        }

        return match ($this->config['credentials']['driver'] ?? 'env') {
            'database' => new DatabaseCredentialResolver($this->config),
            'callback' => throw new FiscalException('Set the callback via OmniTax::resolveCredentialsUsing().'),
            default    => new EnvCredentialResolver($this->config),
        };
    }

    public function transport(): Transport
    {
        $mode = $this->config['transport'] ?? 'http';

        if ($this->container->bound(Transport::class)) {
            return $this->container->make(Transport::class);
        }

        return $mode === 'mock'
            ? new MockTransport()
            : new HttpTransport((int) ($this->config['timeout'] ?? 30));
    }

    // ---- Engine -----------------------------------------------------------

    protected function run(Invoice $invoice, string $method): FiscalResponse
    {
        $credentials = $this->credentials();
        $this->fillSeller($invoice, $credentials);

        event(new InvoiceSubmitting($invoice, $credentials->authority, $credentials->sandbox));

        $driver = $this->driver($credentials);
        $response = $driver->{$method}($invoice);

        if ($response->isValid()) {
            event(new InvoiceAccepted($invoice, $response, $credentials->authority));
        } else {
            event(new InvoiceRejected($invoice, $response, $credentials->authority));
        }

        return $response;
    }

    /** Fall back to the credentials' seller identity when the invoice omits it. */
    protected function fillSeller(Invoice $invoice, Credentials $credentials): void
    {
        if ((! $invoice->seller || ! $invoice->seller->isComplete()) && $credentials->seller) {
            $invoice->seller = $credentials->seller;
        }
    }

    public function config(): array
    {
        return $this->config;
    }
}
