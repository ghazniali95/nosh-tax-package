<?php

namespace Nosh\OmniTax\Events;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Responses\FiscalResponse;

/** Fires when the authority accepted the invoice. */
class InvoiceAccepted
{
    public function __construct(
        public Invoice $invoice,
        public FiscalResponse $response,
        public string $authority,
    ) {
    }
}
