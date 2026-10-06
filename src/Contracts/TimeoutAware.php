<?php

namespace Nosh\OmniTax\Contracts;

/**
 * A transport whose request timeout can be tightened for a single call.
 *
 * Optional on purpose — the Transport contract is unchanged, so a custom
 * transport keeps working. The real-time path (FiscalManager::report) uses it
 * to cap how long a till waits on the authority; a transport without it simply
 * runs at its own timeout.
 */
interface TimeoutAware
{
    /** A copy of this transport that gives up after $seconds. */
    public function withTimeout(float $seconds): static;
}
