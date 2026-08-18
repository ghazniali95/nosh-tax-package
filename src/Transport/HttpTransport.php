<?php

namespace Nosh\OmniTax\Transport;

use Illuminate\Support\Facades\Http;
use Nosh\OmniTax\Contracts\Transport;

/**
 * Real HTTPS transport using Laravel's HTTP client.
 */
class HttpTransport implements Transport
{
    public function __construct(
        protected int $timeout = 30,
    ) {
    }

    public function post(string $url, array $payload, array $headers = []): array
    {
        $response = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        return [
            'status' => $response->status(),
            'body'   => $this->decode($response->body()),
        ];
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        $response = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->acceptJson()
            ->get($url, $query);

        return [
            'status' => $response->status(),
            'body'   => $this->decode($response->body()),
        ];
    }

    protected function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['raw' => $body];
    }
}
