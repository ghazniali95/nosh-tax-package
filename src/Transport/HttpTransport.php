<?php

namespace Nosh\OmniTax\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Nosh\OmniTax\Contracts\TimeoutAware;
use Nosh\OmniTax\Contracts\Transport;

/**
 * Real HTTPS calls to an authority.
 *
 * A network failure (DNS, refused, TLS, timeout) is RETURNED as status 0 rather
 * than thrown. Every caller already treats 0 as "never reached the authority —
 * retry later", and a thrown ConnectionException used to skip that logic: the
 * queued job failed outright, and a synchronous caller got an exception in the
 * middle of a payment.
 */
class HttpTransport implements Transport, TimeoutAware
{
    public function __construct(
        protected float $timeout = 30,
        protected bool $forceIpv4 = false,
        protected ?float $connectTimeout = null,
    ) {
    }

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->timeout = $seconds;
        // Never wait longer to CONNECT than the whole call is allowed to take.
        $clone->connectTimeout = min($this->connectTimeout ?? $seconds, $seconds);

        return $clone;
    }

    public function post(string $url, array $payload, array $headers = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->asJson()->post($url, $payload), $headers);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->get($url, $query), $headers);
    }

    protected function send(callable $call, array $headers): array
    {
        try {
            $response = $call($this->request($headers));
        } catch (ConnectionException $e) {
            return [
                'status' => 0,
                'body'   => [
                    'error'   => $e->getMessage(),
                    'timeout' => str_contains(strtolower($e->getMessage()), 'timed out'),
                ],
            ];
        }

        return [
            'status' => $response->status(),
            'body'   => $this->decode($response->body()),
        ];
    }

    protected function request(array $headers): PendingRequest
    {
        // Guzzle options rather than ->timeout()/->connectTimeout(): those take
        // whole seconds on Laravel 10, and a real-time budget like 2.5s must
        // not be rounded up.
        $request = Http::withHeaders($headers)
            ->acceptJson()
            ->withOptions(array_filter([
                'timeout'         => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
            ], fn ($v) => $v !== null));

        // Authorities that whitelist the caller's IP (PRA cloud) see only the
        // IPv4 a server was registered with. A dual-stack host otherwise goes
        // out over IPv6 and is refused as an unknown address.
        if ($this->forceIpv4 && defined('CURLOPT_IPRESOLVE')) {
            $request = $request->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]]);
        }

        return $request;
    }

    protected function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['raw' => $body];
    }
}
