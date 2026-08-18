<?php

namespace Nosh\OmniTax\Events;

use Nosh\OmniTax\Data\Invoice;

/** Fires just before a call to the authority. */
class InvoiceSubmitting
{
    public function __construct(
        public Invoice $invoice,
        public string $authority,
        public bool $sandbox,
    ) {
    }
}
