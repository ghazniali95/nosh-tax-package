<?php

namespace Nosh\OmniTax\Drivers;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Support\Feature;

/**
 * KPRA (Khyber Pakhtunkhwa Revenue Authority) RIMS driver.
 *
 * Maps the canonical Invoice → KPRA's "RIMS" POS payload and parses KPRA's
 * `{status, message, data:{transaction_id, invoice_no, pos_id, date_time}}`
 * response back into a neutral FiscalResponse. Per KPRA's RIMS API docs &
 * OpenAPI spec:
 *
 *   • Cloud mode   → POST https://kpra.gov.pk/api/rims-integration
 *                    (used by an online/website deployment; every sale is sent
 *                    to KPRA in real time over HTTPS)
 *   • Offline mode → POST http://localhost:3000/api/invoice
 *                    (the KPRA RIMS Windows utility on the till's own machine —
 *                    it queues while offline and syncs to KPRA when back online)
 *
 * The request body is IDENTICAL across the two modes (the utility takes the same
 * `UtilityInvoice` shape as the live `LiveInvoice`), so one driver serves both
 * the website and the desktop app; the deployment picks the mode via
 * config/credentials. KPRA authenticates per-request with `ntn` + `pos_id` +
 * `key` carried IN THE BODY (no bearer token), for both modes.
 *
 * Like SRB, KPRA works at the invoice-total level (a single tax rate, one
 * tax-exclusive `amount`, the `tax_amount`, and a tax-inclusive `total_amount`)
 * rather than per line item, and its QR encodes the returned verification URL
 * (`…/api/?pos_id=<posId>&invoice_no=<invoiceNo>`), not the fiscal number.
 *
 * Success is HTTP 201 (note: FBR/SRB use resCode "00", PRA uses code "100").
 * Refunds are a separate document: a credit note goes to KPRA's dedicated
 * `/kpra-credit-note` endpoint with a `credit_note_no`, referencing the original
 * sale by its `invoice_no`.
 */
class KpraDriver extends AbstractDriver
{
    public const MODE_CLOUD = 'cloud';
    public const MODE_OFFLINE = 'offline';

    protected function features(): array
    {
        return [Feature::CREDIT_NOTE, Feature::OFFLINE_MODE];
    }

    public function key(): string
    {
        return 'kpra';
    }

    /**
     * KPRA exposes no dry-run endpoint, so validate() checks the invoice locally
     * against KPRA's documented rules and returns a pass/fail with no fiscal
     * number — a safe pre-flight before the real submit().
     */
    public function validate(Invoice $invoice): FiscalResponse
    {
        [, $errors] = $this->buildPayload($invoice);

        if ($errors) {
            return new FiscalResponse(
                valid: false,
                errors: $errors,
                raw: ['localValidation' => $errors],
                httpStatus: 200,
            );
        }

        return new FiscalResponse(
            valid: true,
            statusCode: '201',
            status: 'Valid',
            raw: ['localValidation' => 'ok'],
            httpStatus: 200,
        );
    }

    public function submit(Invoice $invoice): FiscalResponse
    {
        [$payload, $errors] = $this->buildPayload($invoice);

        // Fail fast on local rule violations rather than burning a KPRA call.
        if ($errors) {
            return new FiscalResponse(
                valid: false,
                errors: $errors,
                raw: ['localValidation' => $errors, 'payload' => $payload],
                httpStatus: 422,
            );
        }

        return $this->send($this->endpoint($invoice), $payload);
    }

    /** KPRA publishes no server-to-server reference lists. */
    public function reference(string $type): array
    {
        return [];
    }

    // ---- Mode / endpoint / headers ----------------------------------------

    public function mode(): string
    {
        $mode = strtolower((string) ($this->credentials->mode ?? $this->config['mode'] ?? self::MODE_CLOUD));

        return $mode === self::MODE_OFFLINE ? self::MODE_OFFLINE : self::MODE_CLOUD;
    }

    /**
     * Where this invoice goes. A credit note always goes to KPRA's dedicated
     * cloud credit-note endpoint (the offline utility handles sales only); a
     * normal sale goes to the live API (cloud) or the local utility (offline).
     */
    protected function endpoint(Invoice $invoice): string
    {
        $urls = $this->config['urls'] ?? [];

        if ($invoice->isCreditNote()) {
            return $urls['credit_note'] ?? 'https://kpra.gov.pk/api/kpra-credit-note';
        }

        return $this->mode() === self::MODE_OFFLINE
            ? ($urls['offline'] ?? 'http://localhost:3000/api/invoice')
            : ($urls['cloud'] ?? 'https://kpra.gov.pk/api/rims-integration');
    }

    /** KPRA uses plain JSON — no bearer token (credentials ride in the body). */
    protected function headers(): array
    {
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    // ---- Mapping -----------------------------------------------------------

    /**
     * Canonical Invoice → [KPRA payload, local validation errors].
     *
     * A credit note (refund) builds the `/kpra-credit-note` shape; everything
     * else builds the sales-invoice shape. Both carry the `ntn`/`pos_id`/`key`
     * credentials in the body.
     *
     * @return array{0: array<string,mixed>, 1: string[]}
     */
    public function buildPayload(Invoice $invoice): array
    {
        $seller = $invoice->seller;
        $meta = $invoice->meta;
        $errors = [];

        // --- Identity / credentials (in the body for KPRA) ----------------
        $posId = $this->credentials->posId ?? ($meta['posId'] ?? null);
        $key = $this->credentials->apiKey ?? ($meta['key'] ?? null);
        $ntn = trim((string) ($seller?->ntncnic ?? ''));

        if ($posId === null || $posId === '') {
            $errors[] = 'KPRA POS ID is required (register the POS to obtain one).';
        }
        if ($key === null || $key === '') {
            $errors[] = 'KPRA key is required (the secret issued with your POS ID).';
        }
        if ($ntn === '') {
            $errors[] = 'NTN is required.';
        }

        // --- Single tax rate (KPRA carries one rate per invoice) ----------
        $rates = [];
        foreach ($invoice->items as $item) {
            $fraction = $item->rateFraction();
            if ($fraction === null) {
                $errors[] = 'Every line needs a tax rate (KPRA requires a tax rate).';

                continue;
            }
            $rates[(string) round($fraction * 100, 4)] = $fraction;
        }

        if (count($rates) > 1) {
            $errors[] = 'KPRA accepts a single tax rate per invoice, but this invoice mixes rates: '
                .implode('%, ', array_keys($rates)).'%.';
        }

        $rateValue = $rates ? round(reset($rates) * 100, 4) : 0.0;

        // --- Amounts (tax-exclusive amount, tax, tax-inclusive total) -----
        $amount = round($invoice->subtotalExcludingTax(), 2);        // tax-exclusive
        $taxAmount = round($amount * ($rateValue / 100), 2);
        $totalAmount = round($amount + $taxAmount, 2);

        if ($amount <= 0) {
            $errors[] = 'Amount (tax-exclusive) must be greater than zero.';
        }
        if ($rateValue < 0 || $rateValue > 100) {
            $errors[] = 'Tax rate must be between 0 and 100.';
        }

        $invoiceNo = $this->invoiceNo($invoice);
        if (strlen($invoiceNo) > 50) {
            $errors[] = 'invoice_no must be at most 50 characters.';
        }

        // --- Credit note (refund) → the dedicated endpoint's shape --------
        if ($invoice->isCreditNote()) {
            $creditNoteNo = (string) ($meta['creditNoteNo'] ?? $meta['credit_note_no'] ?? $invoiceNo);
            $originalNo = (string) ($meta['refInvoiceNo'] ?? $meta['originalInvoiceNo'] ?? $invoice->invoiceRefNo);

            if ($originalNo === '') {
                $errors[] = 'A KPRA credit note needs the original sale\'s invoice_no (set meta[refInvoiceNo]).';
            }
            if (strlen($creditNoteNo) > 100) {
                $errors[] = 'credit_note_no must be at most 100 characters.';
            }

            $payload = [
                'ntn'            => $ntn,
                'pos_id'         => (string) $posId,
                'key'            => (string) $key,
                'invoice_no'     => $originalNo,          // the ORIGINAL sale
                'credit_note_no' => $creditNoteNo,
                'note_type'      => $this->noteType($meta['noteType'] ?? $meta['note_type'] ?? 'full'),
                'reason'         => $this->trimTo((string) ($meta['reason'] ?? ''), 255),
                'amount'         => $amount,
                'tax_rate'       => $rateValue,
                'tax_amount'     => $taxAmount,
                'total_amount'   => $totalAmount,
                'note_date'      => $this->dateTime($invoice),
            ];

            return [$payload, $errors];
        }

        // --- Normal sale --------------------------------------------------
        $payload = [
            'ntn'          => $ntn,
            'pos_id'       => (string) $posId,
            'key'          => (string) $key,
            'invoice_no'   => $invoiceNo,
            'amount'       => $amount,
            'tax_rate'     => $rateValue,
            'tax_amount'   => $taxAmount,
            'total_amount' => $totalAmount,
            'date_time'    => $this->dateTime($invoice),
            'payment_mode' => $this->paymentMode($meta['modeOfPay'] ?? $meta['paymentMode'] ?? null),
        ];

        return [$payload, $errors];
    }

    // ---- Response parsing --------------------------------------------------

    /**
     * KPRA response → neutral FiscalResponse. Success is HTTP 201 with a
     * `data.transaction_id`. The QR encodes KPRA's verification URL built from
     * the returned `pos_id` + `invoice_no` (a credit note has no QR).
     */
    public function parse(int $httpStatus, array $body): FiscalResponse
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        $isCreditNote = array_key_exists('credit_note_id', $data);

        // A credit note is OK at 201 (recorded) or 200 (already recorded).
        $valid = $isCreditNote
            ? in_array($httpStatus, [200, 201], true) && ! empty($data['credit_note_id'])
            : $httpStatus === 201 && ! empty($data['transaction_id']);

        $invoiceNumber = $isCreditNote
            ? (isset($data['credit_note_id']) ? (string) $data['credit_note_id'] : null)
            : (isset($data['transaction_id']) ? (string) $data['transaction_id'] : null);

        // Verification URL for the receipt QR (sales invoices only).
        $qrPayload = null;
        if (! $isCreditNote && $valid && ! empty($data['pos_id']) && ! empty($data['invoice_no'])) {
            $base = rtrim((string) ($this->config['urls']['verify'] ?? 'https://kpra.gov.pk/api/'), '/').'/';
            $qrPayload = $base.'?pos_id='.rawurlencode((string) $data['pos_id'])
                .'&invoice_no='.rawurlencode((string) $data['invoice_no']);
        }

        return new FiscalResponse(
            valid: $valid,
            invoiceNumber: $invoiceNumber,
            dated: $data['date_time'] ?? $data['note_date'] ?? null,
            statusCode: isset($body['status']) ? (string) $body['status'] : (string) $httpStatus,
            status: $body['message'] ?? ($valid ? 'Valid' : 'Invalid'),
            errors: $valid ? [] : $this->extractErrors($httpStatus, $body),
            raw: $body,
            httpStatus: $httpStatus,
            qrPayload: $qrPayload,
        );
    }

    /** @return string[] */
    protected function extractErrors(int $httpStatus, array $body): array
    {
        $errors = [];

        if (! empty($body['message'])) {
            $errors[] = (string) $body['message'];
        }
        if (! empty($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $field => $msgs) {
                $errors[] = is_array($msgs) ? $field.': '.implode(', ', $msgs) : (string) $msgs;
            }
        }

        if (! $errors) {
            $errors[] = match (true) {
                $httpStatus === 400 => 'KPRA rejected the invoice (bad request — check the required fields and formats).',
                $httpStatus === 401 => 'KPRA authentication failed (check the POS ID and key).',
                $httpStatus === 403 => 'KPRA requires HTTPS for the live API.',
                $httpStatus === 404 => 'KPRA could not find the original invoice for this credit note.',
                $httpStatus === 405 => 'KPRA expects a POST request.',
                $httpStatus >= 500  => "KPRA service error (HTTP {$httpStatus}).",
                default             => "KPRA rejected the request (HTTP {$httpStatus}).",
            };
        }

        return $errors;
    }

    // ---- Field helpers -----------------------------------------------------

    /** 1 = Cash (default), 2 = Card (per KPRA payment_mode). */
    protected function paymentMode(?string $mode): int
    {
        $m = strtolower((string) $mode);

        return str_contains($m, 'card') ? 2 : 1;
    }

    /** KPRA credit note type: 'full' (default) or 'partial'. */
    protected function noteType(string $type): string
    {
        return strtolower(trim($type)) === 'partial' ? 'partial' : 'full';
    }

    /** Our unique invoice number (USIN): explicit meta wins, else the ref, else a short stable key. */
    protected function invoiceNo(Invoice $invoice): string
    {
        $explicit = $invoice->meta['invoiceId'] ?? $invoice->meta['invoice_no'] ?? null;
        if ($explicit) {
            return (string) $explicit;
        }
        if ($invoice->invoiceRefNo !== '') {
            return $invoice->invoiceRefNo;
        }

        return 'INV-'.strtoupper(substr($invoice->key(), 0, 12));
    }

    /** KPRA requires yyyy-MM-dd HH:mm:ss. Accept a date-only invoice and stamp a time. */
    protected function dateTime(Invoice $invoice): string
    {
        $explicit = $invoice->meta['invoiceDateTime'] ?? $invoice->meta['date_time'] ?? null;
        if ($explicit) {
            return (string) $explicit;
        }

        $date = (string) ($invoice->date ?? date('Y-m-d'));
        if (preg_match('/\d{2}:\d{2}:\d{2}/', $date)) {
            return $date;
        }

        return substr($date, 0, 10).' '.date('H:i:s');
    }

    protected function trimTo(string $value, int $max): string
    {
        $value = trim($value);

        return $value === '' ? '' : mb_substr($value, 0, $max);
    }
}
