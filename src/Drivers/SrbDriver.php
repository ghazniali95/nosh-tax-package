<?php

namespace Nosh\OmniTax\Drivers;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Data\LineItem;
use Nosh\OmniTax\Responses\FiscalResponse;

/**
 * SRB (Sindh Revenue Board) POS driver.
 *
 * Maps the canonical Invoice → SRB's POS SalesInvoice payload and parses SRB's
 * `{srbInvoiceId, resCode, QRCodeLink}` response back into a neutral
 * FiscalResponse. Per the official SRB POS API guides (v1.0):
 *
 *   • Cloud mode   → POST https://pos.srb.gos.pk/ePOSGateway/v1/SalesInvoiceService.api
 *                    (used by an online/website deployment; sends posUser/posPass
 *                    in the JSON body)
 *   • Offline mode → POST http://localhost:8282/pos/SalesInvoiceServices
 *                    (the SRB POS Connector running on the desktop app's own
 *                    machine; the Connector holds the credentials, so no
 *                    posUser/posPass are sent)
 *
 * The payload, validations, calculation rules and response are identical across
 * the two modes — only the endpoint and whether credentials ride in the body
 * differ. One driver therefore serves both the website and the desktop app; the
 * deployment picks the mode via config/credentials.
 *
 * Unlike FBR, SRB works at the invoice-total level (a single tax rate, gross
 * sale value, tax and net amounts) rather than per line item, and its QR encodes
 * the returned verification URL (not the fiscal number).
 */
class SrbDriver extends AbstractDriver
{
    public const MODE_CLOUD = 'cloud';
    public const MODE_OFFLINE = 'offline';

    public function key(): string
    {
        return 'srb';
    }

    /**
     * SRB exposes no dry-run endpoint, so validate() checks the invoice locally
     * against SRB's documented rules (§9) and returns a pass/fail with no fiscal
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
            statusCode: '00',
            status: 'Valid',
            raw: ['localValidation' => 'ok'],
            httpStatus: 200,
        );
    }

    public function submit(Invoice $invoice): FiscalResponse
    {
        [$payload, $errors] = $this->buildPayload($invoice);

        // Fail fast on local rule violations rather than burning an SRB call.
        if ($errors) {
            return new FiscalResponse(
                valid: false,
                errors: $errors,
                raw: ['localValidation' => $errors, 'payload' => $payload],
                httpStatus: 422,
            );
        }

        $result = $this->transport->post($this->endpoint(), $payload, $this->headers());

        return $this->parse($result['status'] ?? 0, $result['body'] ?? []);
    }

    /** SRB publishes no server-to-server reference lists. */
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

    protected function endpoint(): string
    {
        $urls = $this->config['urls'] ?? [];

        return $this->mode() === self::MODE_OFFLINE
            ? ($urls['offline'] ?? 'http://localhost:8282/pos/SalesInvoiceServices')
            : ($urls['cloud'] ?? 'https://pos.srb.gos.pk/ePOSGateway/v1/SalesInvoiceService.api');
    }

    /** SRB uses plain JSON — no bearer token (credentials ride in the body for cloud). */
    protected function headers(): array
    {
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    // ---- Mapping -----------------------------------------------------------

    /**
     * Canonical Invoice → [SRB payload, local validation errors].
     *
     * @return array{0: array<string,mixed>, 1: string[]}
     */
    public function buildPayload(Invoice $invoice): array
    {
        $seller = $invoice->seller;
        $buyer = $invoice->buyer;
        $meta = $invoice->meta;
        $errors = [];

        // --- Identity -----------------------------------------------------
        $posId = $this->credentials->posId ?? ($meta['posId'] ?? null);
        $ntn = $this->sanitizeNtn((string) ($seller?->ntncnic ?? ''));
        $name = $this->stripSpecialChars((string) ($seller?->name ?? ''));

        if ($posId === null || $posId === '' || (int) $posId <= 0) {
            $errors[] = 'SRB POS ID is required (register the POS to obtain one).';
        }
        if ($ntn === '') {
            $errors[] = 'NTN is required (send it without a leading S and without the digit after the hyphen).';
        }
        if ($name === '') {
            $errors[] = 'Business/Restaurant name is required.';
        }
        if ($this->mode() === self::MODE_CLOUD
            && (empty($this->credentials->posUser) || empty($this->credentials->posPass))) {
            $errors[] = 'SRB cloud mode requires posUser and posPass credentials.';
        }

        // --- Single tax rate (SRB is one-rate-per-invoice) ----------------
        $rates = [];
        foreach ($invoice->items as $item) {
            $fraction = $item->rateFraction();
            if ($fraction === null) {
                $errors[] = 'Every line needs a tax rate (SRB rejects missing rates).';

                continue;
            }
            $rates[(string) round($fraction * 100, 4)] = $fraction;
        }

        if (count($rates) > 1) {
            $errors[] = 'SRB accepts a single tax rate per invoice, but this invoice mixes rates: '
                .implode('%, ', array_keys($rates)).'%.';
        }

        $rateValue = $rates ? round(reset($rates) * 100, 4) : 0.0;

        // --- Amounts (SRB's exact formulae, §8) ---------------------------
        $saleValue = $invoice->subtotalExcludingTax();               // gross sale, net of line discounts
        $serviceCharges = (float) ($meta['serviceCharges'] ?? 0);
        $extraCharges = (float) ($meta['extraCharges'] ?? 0);
        $discountAmount = (float) ($meta['discountAmount'] ?? 0);

        $taxAmount = round(($saleValue + $serviceCharges + $extraCharges) * ($rateValue / 100), 2);
        $netAmount = round($saleValue + $serviceCharges + $extraCharges + $taxAmount - $discountAmount, 2);

        if ($saleValue <= 0) {
            $errors[] = 'Sale value must be greater than zero.';
        }

        // --- Assemble -----------------------------------------------------
        $payload = [
            'posId'          => (int) $posId,
            'name'           => $name,
            'ntn'            => $ntn,
            'invoiceDateTime' => $this->dateTime($invoice),
            'invoiceType'    => $this->invoiceType($invoice->type),
            'invoiceId'      => $this->invoiceId($invoice),
            'rateValue'      => $rateValue,
            'saleValue'      => round($saleValue, 2),
            'taxAmount'      => $taxAmount,
            'discountAmount' => round($discountAmount, 2),
            'serviceCharges' => round($serviceCharges, 2),
            'extraCharges'   => round($extraCharges, 2),
            'netAmount'      => $netAmount,
            'consumerName'   => $this->optional($this->consumerName($buyer)),
            'consumerMobile' => $this->optional($meta['consumerMobile'] ?? null),
            'consumerEmail'  => $this->optional($meta['consumerEmail'] ?? null),
            'consumerNTN'    => $this->optional($buyer?->ntncnic),
            'address'        => $this->optional($buyer?->address),
            'cpcCode'        => $this->optional($meta['cpcCode'] ?? null),
            'extraInf'       => $this->optional($meta['extraInf'] ?? null),
            'modeOfPay'      => $meta['modeOfPay'] ?? 'Cash',
            'transType'      => $this->credentials->sandbox ? 'Test' : 'Live',
        ];

        // Cloud mode carries the gateway credentials in the body; the offline
        // Connector supplies them itself, so they're omitted there.
        if ($this->mode() === self::MODE_CLOUD) {
            $payload['posUser'] = (string) ($this->credentials->posUser ?? '');
            $payload['posPass'] = (string) ($this->credentials->posPass ?? '');
        }

        return [$payload, $errors];
    }

    // ---- Response parsing --------------------------------------------------

    /**
     * SRB response → neutral FiscalResponse. Success is `resCode === "00"` with
     * an `srbInvoiceId`; the QR encodes the returned `QRCodeLink` URL.
     */
    public function parse(int $httpStatus, array $body): FiscalResponse
    {
        $resCode = isset($body['resCode']) ? (string) $body['resCode'] : null;
        $srbInvoiceId = $body['srbInvoiceId'] ?? null;
        $valid = $resCode === '00' && $httpStatus === 200 && ! empty($srbInvoiceId);

        return new FiscalResponse(
            valid: $valid,
            invoiceNumber: $srbInvoiceId,
            dated: $body['dated'] ?? null,
            statusCode: $resCode,
            status: $body['status'] ?? ($valid ? 'Valid' : 'Invalid'),
            errors: $valid ? [] : $this->extractErrors($httpStatus, $body),
            raw: $body,
            httpStatus: $httpStatus,
            qrPayload: $body['QRCodeLink'] ?? null,
        );
    }

    /** @return string[] */
    protected function extractErrors(int $httpStatus, array $body): array
    {
        $errors = [];

        if (! empty($body['error'])) {
            $errors[] = trim((($body['resCode'] ?? '').' – ').$body['error'], ' –');
        }
        if (! empty($body['Required Parameter(s) Missing'])) {
            $errors[] = 'Missing required parameter(s): '.$body['Required Parameter(s) Missing'];
        }
        if ($httpStatus >= 500) {
            $errors[] = "SRB service error (HTTP {$httpStatus}).";
        }
        if (! $errors) {
            $errors[] = 'SRB rejected the invoice (resCode '.($body['resCode'] ?? '?').').';
        }

        return $errors;
    }

    // ---- Field helpers -----------------------------------------------------

    /**
     * SRB wants the NTN without a leading "S" and without the check digit that
     * follows a hyphen (e.g. "S1234567-8" → "1234567").
     */
    protected function sanitizeNtn(string $ntn): string
    {
        $ntn = trim($ntn);
        $ntn = preg_replace('/^[Ss]/', '', $ntn);      // drop leading S
        $ntn = preg_replace('/-.*$/', '', (string) $ntn); // drop hyphen + check digit

        return trim((string) $ntn);
    }

    /** SRB rejects special characters in the business name; collapse the gaps they leave. */
    protected function stripSpecialChars(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9 ]+/', '', $name);
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /** 1 = Normal Invoice, 2 = Sales Return (per SRB invoiceType). */
    protected function invoiceType(string $type): int
    {
        $t = strtolower($type);

        return (str_contains($t, 'return') || str_contains($t, 'debit') || str_contains($t, 'credit')) ? 2 : 1;
    }

    /** Our unique invoice number: explicit meta wins, else the ref, else a short stable key. */
    protected function invoiceId(Invoice $invoice): string
    {
        $explicit = $invoice->meta['invoiceId'] ?? null;
        if ($explicit) {
            return (string) $explicit;
        }
        if ($invoice->invoiceRefNo !== '') {
            return $invoice->invoiceRefNo;
        }

        return 'INV-'.strtoupper(substr($invoice->key(), 0, 12));
    }

    /** SRB requires yyyy-MM-dd HH:mm:ss. Accept a date-only invoice and stamp a time. */
    protected function dateTime(Invoice $invoice): string
    {
        $explicit = $invoice->meta['invoiceDateTime'] ?? null;
        if ($explicit) {
            return (string) $explicit;
        }

        $date = (string) ($invoice->date ?? date('Y-m-d'));
        if (preg_match('/\d{2}:\d{2}:\d{2}/', $date)) {
            return $date;
        }

        return substr($date, 0, 10).' '.date('H:i:s');
    }

    protected function consumerName(?object $buyer): ?string
    {
        $name = $buyer?->name ?? null;

        // A generic walk-in marker is not a real consumer name.
        return $name && strcasecmp($name, 'Walk-in Customer') !== 0 ? $name : null;
    }

    /** Optional SRB fields are sent as "N/A" when absent (per §10). */
    protected function optional(?string $value): string
    {
        $value = $value !== null ? trim($value) : '';

        return $value === '' ? 'N/A' : $value;
    }
}
