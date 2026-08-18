<?php

namespace Nosh\OmniTax\Events;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Responses\FiscalResponse;

/** Fires when the authority rejected the invoice (with errors). */
class InvoiceRejected
{
    public function __construct(
        public Invoice $invoice,
        public FiscalResponse $response,
        public string $authority,
    ) {
    }
}
