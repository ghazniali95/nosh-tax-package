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
use Nosh\OmniTax\Contracts\TimeoutAware;
use Nosh\OmniTax\Exceptions\FiscalException;
use Nosh\OmniTax\Jobs\SubmitFiscalInvoice;
use Nosh\OmniTax\Models\FiscalInvoice;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Responses\HealthCheck;
use Nosh\OmniTax\Support\Feature;
use Nosh\OmniTax\Testing\Scenario;
use Nosh\OmniTax\Support\Qr\Logo;
use Nosh\OmniTax\Transport\HttpTransport;
use Nosh\OmniTax\Transport\KpraMockTransport;
use Nosh\OmniTax\Transport\MockTransport;
use Nosh\OmniTax\Transport\PraMockTransport;
use Nosh\OmniTax\Transport\SrbMockTransport;

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
    protected ?float $timeoutOverride = null;
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

    /** Cap how long THIS call waits on the authority, in seconds. */
    public function timeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->timeoutOverride = $seconds;

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

    /**
     * Report a sale: try the authority in real time, and queue it if that
     * cannot finish in time. Returns the persisted record either way.
     *
     * The "real-time" attempt is capped at `omnitax.realtime.timeout` seconds
     * (default 3) so a slow government API never holds up a payment. Outcomes:
     *
     *   accepted        → record is `valid`, fiscal number + QR ready to print
     *   rejected        → record is `failed` (bad data / credentials); NOT
     *                     queued, because the same payload would be refused again
     *   unreachable/5xx → record stays `pending` and a SubmitFiscalInvoice job is
     *                     queued (after the surrounding DB transaction commits)
     *
     * With `omnitax.realtime.enabled = false` (or `$realtime = false`) nothing is
     * attempted inline — the record is queued straight away.
     *
     * Idempotent: reporting a sale that is already accepted returns its record
     * without contacting the authority. Give the invoice its own `->number()`.
     *
     * @param  string|null  $reference  your key for the sale, e.g. "bill:1234"
     */
    public function report(Invoice $invoice, ?string $reference = null, ?bool $realtime = null): FiscalInvoice
    {
        $credentials = $this->credentials();
        // Before the key is taken: the seller's NTN is part of it.
        $this->fillSeller($invoice, $credentials);

        $record = FiscalInvoice::fromInvoice($invoice, $this->tenant, $credentials->authority, $reference);

        if ($record->isReported()) {
            return $record;
        }

        if ($realtime ?? $this->realtimeEnabled()) {
            $response = $this->timeout($this->realtimeTimeout())->submit($invoice);
            $record->recordResponse($response);

            if (! $response->isRetryable()) {
                return $record;
            }
        }

        SubmitFiscalInvoice::dispatch($record)->afterCommit();

        return $record;
    }

    public function realtimeEnabled(): bool
    {
        return (bool) ($this->config['realtime']['enabled'] ?? true);
    }

    public function realtimeTimeout(): float
    {
        return (float) ($this->config['realtime']['timeout'] ?? 3);
    }

    /** Does this business's authority support a feature? See {@see Feature}. */
    public function supports(string $feature): bool
    {
        $driver = $this->driver();

        return method_exists($driver, 'supports') && $driver->supports($feature);
    }

    /** True when credentials resolve for the current tenant/authority. */
    public function isConfigured(): bool
    {
        try {
            return $this->credentialResolver()->resolve($this->tenant, $this->authority) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * "Test connection" for a settings page: credentials resolve, the seller is
     * complete, the authority's driver is available, and a sample invoice
     * validates. For an authority with remote validation (FBR) that last step
     * is a real call with the real token; for PRA/SRB it is a local check of
     * the configuration — see HealthCheck::reachedAuthority().
     */
    public function check(): HealthCheck
    {
        $checks = [];

        try {
            $credentials = $this->credentialResolver()->resolve($this->tenant, $this->authority);
        } catch (\Throwable $e) {
            $credentials = null;
        }

        if (! $credentials) {
            $checks['credentials'] = ['ok' => false, 'message' => 'No fiscal credentials are saved for this business.'];

            return new HealthCheck($checks);
        }
        $checks['credentials'] = ['ok' => true, 'message' => 'Credentials found.'];

        $seller = $credentials->seller;
        $sellerOk = $seller && $seller->isComplete();
        $checks['seller'] = [
            'ok'      => $sellerOk,
            'message' => $sellerOk ? 'Business identity is complete.' : 'NTN/CNIC, business name, province and address are all required.',
        ];
        if (! $sellerOk) {
            return new HealthCheck($checks, $credentials->authority, $credentials->mode, $credentials->sandbox);
        }

        try {
            $driver = $this->driver($credentials);
        } catch (FiscalException $e) {
            $checks['authority'] = ['ok' => false, 'message' => $e->getMessage()];

            return new HealthCheck($checks, $credentials->authority, $credentials->mode, $credentials->sandbox);
        }
        $checks['authority'] = ['ok' => true, 'message' => ($this->config['authorities'][$credentials->authority]['label'] ?? $credentials->authority).' is available.'];

        $mode = method_exists($driver, 'mode') ? $driver->mode() : $credentials->mode;
        $remote = method_exists($driver, 'supports') && $driver->supports(Feature::REMOTE_VALIDATION);

        $sample = Scenario::make('SN019');
        $sample->seller = $seller;
        $response = $driver->validate($sample);

        $checks['validate'] = [
            'ok'      => $response->isValid(),
            'message' => $response->isValid()
                ? ($remote ? 'The authority accepted a test invoice.' : 'Configuration is complete (checked locally — this authority has no test endpoint).')
                : implode(' | ', $response->errors()),
        ];

        return new HealthCheck($checks, $credentials->authority, $mode, $credentials->sandbox, $remote);
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

        return new $driverClass($credentials, $authConfig, $this->transport($authKey));
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

    public function transport(?string $authority = null): Transport
    {
        $mode = $this->config['transport'] ?? 'http';

        if ($this->container->bound(Transport::class)) {
            return $this->applyTimeout($this->container->make(Transport::class));
        }

        if ($mode === 'mock') {
            // Each authority's mock returns responses in that authority's own
            // documented shape.
            return match ($authority ?? $this->authority ?? $this->config['default'] ?? 'fbr') {
                'srb'   => new SrbMockTransport(),
                'pra'   => new PraMockTransport(),
                'kpra'  => new KpraMockTransport(),
                default => new MockTransport(),
            };
        }

        return $this->applyTimeout(new HttpTransport(
            (float) ($this->config['timeout'] ?? 30),
            (bool) ($this->config['http']['force_ipv4'] ?? false),
            isset($this->config['http']['connect_timeout']) ? (float) $this->config['http']['connect_timeout'] : null,
        ));
    }

    protected function applyTimeout(Transport $transport): Transport
    {
        return $this->timeoutOverride !== null && $transport instanceof TimeoutAware
            ? $transport->withTimeout($this->timeoutOverride)
            : $transport;
    }

    // ---- Engine -----------------------------------------------------------

    protected function run(Invoice $invoice, string $method): FiscalResponse
    {
        $credentials = $this->credentials();
        $this->fillSeller($invoice, $credentials);

        event(new InvoiceSubmitting($invoice, $credentials->authority, $credentials->sandbox));

        $driver = $this->driver($credentials);

        // A refund the authority has no document for is refused here, clearly,
        // rather than sent as an ordinary sale (which would ADD the amount).
        if ($invoice->isCreditNote() && ! (method_exists($driver, 'supports') && $driver->supports(Feature::CREDIT_NOTE))) {
            $label = $this->config['authorities'][$credentials->authority]['label'] ?? $credentials->authority;
            $response = new FiscalResponse(
                valid: false,
                errors: ["{$label} does not accept credit notes, so this refund cannot be reported to it."],
                httpStatus: 422,
            );
            event(new InvoiceRejected($invoice, $response, $credentials->authority));

            return $response;
        }

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
