<?php

namespace Nosh\OmniTax\Contracts;

/**
 * Abstracts the actual network call so a driver can run against real HTTPS
 * or against an in-package mock (no token / no network) for testing.
 */
interface Transport
{
    /**
     * @param  array<string,string>  $headers
     * @return array{status:int, body:array}
     */
    public function post(string $url, array $payload, array $headers = []): array;

    /**
     * @param  array<string,string>  $headers
     * @return array{status:int, body:array}
     */
    public function get(string $url, array $query = [], array $headers = []): array;
}
