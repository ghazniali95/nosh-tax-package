<?php

namespace Nosh\OmniTax\Support;

/**
 * Things an authority may or may not support, asked of a driver with
 * `supports()` (or `OmniTax::for($t)->supports()`), so calling code can decide
 * up front instead of learning from a rejection.
 */
final class Feature
{
    /** A credit note / sales return that references an earlier invoice. */
    public const CREDIT_NOTE = 'credit_note';

    /** An "offline" mode through a component installed on the POS machine. */
    public const OFFLINE_MODE = 'offline_mode';

    /** validate() is checked by the authority itself, not only locally. */
    public const REMOTE_VALIDATION = 'remote_validation';

    private function __construct()
    {
    }
}
