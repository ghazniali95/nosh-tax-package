<?php

namespace Nosh\OmniTax\Responses;

/**
 * The answer to "is this business set up to report?" — what a settings page's
 * "Test connection" button shows.
 *
 * Each check is [name => ['ok' => bool, 'message' => string]], in the order
 * they ran; the first failure stops the rest, because a later check cannot be
 * meaningful without the earlier one (no credentials → nothing to validate).
 */
class HealthCheck
{
    /** @param array<string, array{ok: bool, message: string}> $checks */
    public function __construct(
        protected array $checks = [],
        protected ?string $authority = null,
        protected ?string $mode = null,
        protected ?bool $sandbox = null,
        protected bool $remote = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->checks !== [] && ! in_array(false, array_column($this->checks, 'ok'), true);
    }

    /** @return array<string, array{ok: bool, message: string}> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** The first failing check's message, or null when everything passed. */
    public function failure(): ?string
    {
        foreach ($this->checks as $check) {
            if (! $check['ok']) {
                return $check['message'];
            }
        }

        return null;
    }

    public function authority(): ?string
    {
        return $this->authority;
    }

    public function mode(): ?string
    {
        return $this->mode;
    }

    public function sandbox(): ?bool
    {
        return $this->sandbox;
    }

    /**
     * Whether the authority itself was asked. False means the configuration
     * was checked locally only — PRA and SRB publish no cloud "ping", so a
     * passing local check can still be refused by them on the first real sale.
     */
    public function reachedAuthority(): bool
    {
        return $this->remote;
    }

    public function toArray(): array
    {
        return [
            'ok'               => $this->ok(),
            'authority'        => $this->authority,
            'mode'             => $this->mode,
            'sandbox'          => $this->sandbox,
            'reachedAuthority' => $this->remote,
            'checks'           => $this->checks,
        ];
    }
}
