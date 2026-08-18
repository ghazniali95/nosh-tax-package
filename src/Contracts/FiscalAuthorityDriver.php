<?php

namespace Nosh\OmniTax\Contracts;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Responses\FiscalResponse;

/**
 * The single seam every tax authority plugs into.
 *
 * Add a new authority (a new province, or a new country such as KSA ZATCA
 * or Malaysia MyInvois) by implementing this interface — nothing in the
 * calling application changes.
 */
interface FiscalAuthorityDriver
{
    /** Authority key, e.g. "fbr", "pra", "zatca". */
    public function key(): string;

    /** Dry-run: check an invoice without committing it. */
    public function validate(Invoice $invoice): FiscalResponse;

    /** Real-time submit: report the invoice and get back the fiscal number. */
    public function submit(Invoice $invoice): FiscalResponse;

    /** Fetch a reference list (provinces, units, hsCodes, taxRates, ...). */
    public function reference(string $type): array;
}
