<?php

namespace Nosh\OmniTax\Drivers;

use Nosh\OmniTax\Contracts\FiscalAuthorityDriver;
use Nosh\OmniTax\Contracts\Transport;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Responses\FiscalResponse;

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

    /** Turn the authority's HTTP answer into a FiscalResponse. */
    abstract public function parse(int $httpStatus, array $body): FiscalResponse;

    /**
     * Features this authority supports — see {@see \Nosh\OmniTax\Support\Feature}.
     *
     * @return list<string>
     */
    protected function features(): array
    {
        return [];
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * POST to the authority and parse the answer.
     *
     * A request that never reached the authority (status 0) is answered here,
     * not by parse(): there is no authority body to read, and the caller needs
     * one clear "unreachable — retry later" response whichever driver it was.
     */
    protected function send(string $url, array $payload): FiscalResponse
    {
        $result = $this->transport->post($url, $payload, $this->headers());
        $status = (int) ($result['status'] ?? 0);

        if ($status === 0) {
            $body = $result['body'] ?? [];
            $why = ($body['timeout'] ?? false) ? 'timed out' : 'could not be reached';

            return new FiscalResponse(
                valid: false,
                errors: ["The tax authority {$why}. The invoice will be retried."],
                raw: ['transport' => $body],
                httpStatus: 0,
            );
        }

        return $this->parse($status, $result['body'] ?? []);
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
