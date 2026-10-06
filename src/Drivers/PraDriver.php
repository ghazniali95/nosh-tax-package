<?php

namespace Nosh\OmniTax\Drivers;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Data\LineItem;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Support\Feature;

/**
 * PRA (Punjab Revenue Authority / PRAL) Software Fiscal Device driver.
 *
 * Maps the canonical Invoice → PRA's IMS invoice payload and parses PRA's
 * `{InvoiceNumber, Code, Response, Errors}` response into a neutral
 * FiscalResponse. Per the "Technical Specification for Data Sharing through
 * Software Fiscal Device with PRA" (v1.2, PRAL):
 *
 *   • Cloud mode   → POST https://ims.pral.com.pk/ims/{sandbox|production}/api/Live/PostData
 *                    with an `Authorization: Bearer <token>` header. Used by an
 *                    online/website deployment. (PRA also requires the calling
 *                    server's IP to be whitelisted, and TLS 1.2.)
 *   • Offline mode → POST http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel
 *                    to PRA's locally-installed Software Fiscal Device (the "IMS
 *                    component"), which fiscalises the invoice and then syncs to
 *                    PRA on its own schedule. Used by the desktop app; no token —
 *                    the installed component holds the POS credentials.
 *
 * The invoice JSON and the response are identical across both modes, so one
 * driver serves the website and the desktop app; the deployment picks the mode.
 *
 * Like FBR (and unlike SRB) PRA is per-item. Success is `Code === "100"`, and
 * the receipt QR encodes PRA's invoice-verification URL (not the number).
 */
class PraDriver extends AbstractDriver
{
    public const MODE_CLOUD = 'cloud';
    public const MODE_OFFLINE = 'offline';

    /** PRA success code (note: FBR/SRB use "00"; PRA uses "100"). */
    public const SUCCESS_CODE = '100';

    /** InvoiceType 3 (credit / return) with RefUSIN; IMS component on the till. */
    protected function features(): array
    {
        return [Feature::CREDIT_NOTE, Feature::OFFLINE_MODE];
    }

    public function key(): string
    {
        return 'pra';
    }

    /**
     * PRA exposes no dry-run endpoint (the offline component's GET is only a
     * health check), so validate() checks the invoice locally and returns a
     * pass/fail with no fiscal number.
     */
    public function validate(Invoice $invoice): FiscalResponse
    {
        [, $errors] = $this->buildPayload($invoice);

        if ($errors) {
            return new FiscalResponse(valid: false, errors: $errors, raw: ['localValidation' => $errors], httpStatus: 200);
        }

        return new FiscalResponse(valid: true, statusCode: self::SUCCESS_CODE, status: 'Valid', raw: ['localValidation' => 'ok'], httpStatus: 200);
    }

    public function submit(Invoice $invoice): FiscalResponse
    {
        [$payload, $errors] = $this->buildPayload($invoice);

        if ($errors) {
            return new FiscalResponse(valid: false, errors: $errors, raw: ['localValidation' => $errors, 'payload' => $payload], httpStatus: 422);
        }

        return $this->send($this->endpoint(), $payload);
    }

    /** PRA publishes no server-to-server reference lists in this spec. */
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

        if ($this->mode() === self::MODE_OFFLINE) {
            return $urls['offline'] ?? 'http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel';
        }

        return $this->credentials->sandbox
            ? ($urls['cloud_sb'] ?? 'https://ims.pral.com.pk/ims/sandbox/api/Live/PostData')
            : ($urls['cloud'] ?? 'https://ims.pral.com.pk/ims/production/api/Live/PostData');
    }

    /** Cloud carries the Bearer token; the offline component needs no auth. */
    protected function headers(): array
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];

        if ($this->mode() === self::MODE_CLOUD) {
            $headers['Authorization'] = 'Bearer '.($this->credentials->token ?? '');
        }

        return $headers;
    }

    // ---- Mapping -----------------------------------------------------------

    /**
     * Canonical Invoice → [PRA payload, local validation errors].
     *
     * @return array{0: array<string,mixed>, 1: string[]}
     */
    public function buildPayload(Invoice $invoice): array
    {
        $seller = $invoice->seller;
        $buyer = $invoice->buyer;
        $meta = $invoice->meta;
        $errors = [];

        $posId = $this->credentials->posId ?? ($meta['posId'] ?? null);
        if ($posId === null || $posId === '' || (int) $posId <= 0) {
            $errors[] = 'PRA POS ID is required (register the POS to obtain one).';
        }
        if (! $invoice->items) {
            $errors[] = 'An invoice needs at least one item.';
        }
        if ($this->mode() === self::MODE_CLOUD && empty($this->credentials->token)) {
            $errors[] = 'PRA cloud mode requires a Bearer token (sandbox or production).';
        }

        $invoiceType = $this->invoiceType($invoice->type);

        $items = [];
        $totalSale = 0.0;
        $totalTax = 0.0;
        $totalDiscount = 0.0;
        $totalFurther = 0.0;
        $totalBill = 0.0;

        foreach ($invoice->items as $index => $item) {
            // PRA taxes the sale value as entered; discount is recorded separately
            // (its arithmetic: TotalBillAmount = TotalSaleValue + TotalTaxCharged).
            $sale = round($item->unitPrice * $item->quantity, 2);
            $rate = $item->rateFraction();
            $rateValue = $rate === null ? 0.0 : round($rate * 100, 4);
            $tax = $item->taxAmount !== null ? round($item->taxAmount, 2) : round($sale * ($rateValue / 100), 2);
            $further = round($item->furtherTax, 2);
            $total = round($sale + $tax + $further, 2);

            $totalSale += $sale;
            $totalTax += $tax;
            $totalDiscount += round($item->discount, 2);
            $totalFurther += $further;
            $totalBill += $total;

            $items[] = [
                'ItemCode'    => $this->itemCode($item, $index),
                'ItemName'    => $item->description,
                'PCTCode'     => $item->hsCode ?: '00000000',
                'Quantity'    => round($item->quantity, 4),
                'TaxRate'     => $rateValue,
                'SaleValue'   => $sale,
                'Discount'    => round($item->discount, 2),
                'FurtherTax'  => $further,
                'TaxCharged'  => $tax,
                'TotalAmount' => $total,
                'InvoiceType' => $invoiceType,
                'RefUSIN'     => $meta['refUsin'] ?? null,
            ];
        }

        $payload = [
            'InvoiceNumber'    => '',                          // returned by PRA
            'POSID'            => (int) $posId,
            'USIN'             => $this->usin($invoice),
            'RefUSIN'          => $meta['refUsin'] ?? null,
            'DateTime'         => $this->dateTime($invoice),
            'BuyerName'        => $this->buyerName($buyer),
            'BuyerPNTN'        => $buyer?->ntncnic ?? '',
            'BuyerCNIC'        => $meta['buyerCnic'] ?? '',
            'BuyerPhoneNumber' => $meta['buyerPhone'] ?? '',
            'TotalSaleValue'   => round($totalSale, 2),
            'TotalQuantity'    => round(array_sum(array_map(fn (LineItem $i) => $i->quantity, $invoice->items)), 4),
            'TotalBillAmount'  => round($totalBill, 2),
            'TotalTaxCharged'  => round($totalTax, 2),
            'Discount'         => round($totalDiscount, 2),
            'FurtherTax'       => round($totalFurther, 2),
            'PaymentMode'      => $this->paymentMode($meta['paymentMode'] ?? $meta['modeOfPay'] ?? null),
            'InvoiceType'      => $invoiceType,
            'Items'            => $items,
        ];

        return [$payload, $errors];
    }

    // ---- Response parsing --------------------------------------------------

    /**
     * PRA response → neutral FiscalResponse. Success is `Code === "100"` with an
     * `InvoiceNumber`; the QR encodes PRA's verification URL for that number.
     */
    public function parse(int $httpStatus, array $body): FiscalResponse
    {
        if ($httpStatus === 401) {
            return new FiscalResponse(
                valid: false,
                errors: ['Unauthorized — invalid or missing PRA token (check the token and IP whitelisting).'],
                raw: $body,
                httpStatus: 401,
            );
        }

        $code = isset($body['Code']) ? (string) $body['Code'] : null;
        $invoiceNumber = $body['InvoiceNumber'] ?? null;
        $valid = $code === self::SUCCESS_CODE && $httpStatus === 200 && ! empty($invoiceNumber);

        return new FiscalResponse(
            valid: $valid,
            invoiceNumber: $invoiceNumber ?: null,
            statusCode: $code,
            status: $valid ? 'Valid' : 'Invalid',
            errors: $valid ? [] : $this->extractErrors($httpStatus, $body),
            raw: $body,
            httpStatus: $httpStatus,
            qrPayload: $invoiceNumber ? $this->verificationUrl($invoiceNumber) : null,
        );
    }

    /** @return string[] */
    protected function extractErrors(int $httpStatus, array $body): array
    {
        $errors = [];
        if (! empty($body['Errors'])) {
            $errors[] = is_array($body['Errors']) ? implode('; ', $body['Errors']) : (string) $body['Errors'];
        }
        if (! empty($body['Response']) && ($body['Code'] ?? null) !== self::SUCCESS_CODE) {
            $errors[] = trim((($body['Code'] ?? '').' – ').$body['Response'], ' –');
        }
        if ($httpStatus >= 500) {
            $errors[] = "PRA service error (HTTP {$httpStatus}).";
        }
        if (! $errors) {
            $errors[] = 'PRA rejected the invoice (Code '.($body['Code'] ?? '?').').';
        }

        return $errors;
    }

    /** PRA's public invoice-verification URL for a fiscal number (encoded in the QR). */
    public function verificationUrl(string $invoiceNumber): string
    {
        $base = $this->config['urls']['verify'] ?? 'https://reg.pra.punjab.gov.pk/IMSFiscalReport/SearchPOSInvoice_Report.aspx';

        return $base.'?PRAInvNo='.rawurlencode($invoiceNumber);
    }

    // ---- Field helpers -----------------------------------------------------

    /** 1 = New, 2 = Debit, 3 = Credit (return/cancel) — per PRA InvoiceType. */
    protected function invoiceType(string $type): int
    {
        $t = strtolower($type);
        if (str_contains($t, 'credit') || str_contains($t, 'return') || str_contains($t, 'refund') || str_contains($t, 'cancel')) {
            return 3;
        }
        if (str_contains($t, 'debit')) {
            return 2;
        }

        return 1;
    }

    /**
     * PaymentMode int: 1 Cash, 2 Card, 3 Gift Voucher, 4 Loyalty Card, 5 Mixed, 6 Cheque.
     * Accepts an int already, or a name.
     */
    protected function paymentMode(mixed $mode): int
    {
        if (is_int($mode) || (is_string($mode) && ctype_digit($mode))) {
            $n = (int) $mode;

            return $n >= 1 && $n <= 6 ? $n : 1;
        }

        return match (strtolower(trim((string) $mode))) {
            'card', 'credit card', 'debit card' => 2,
            'gift', 'gift voucher', 'voucher'   => 3,
            'loyalty', 'loyalty card', 'points' => 4,
            'mixed', 'split'                    => 5,
            'cheque', 'check'                   => 6,
            default                             => 1, // cash
        };
    }

    /** Our own invoice number (PRA `USIN`): explicit meta wins, else the ref, else a stable key. */
    protected function usin(Invoice $invoice): string
    {
        $explicit = $invoice->meta['usin'] ?? $invoice->meta['invoiceId'] ?? null;
        if ($explicit) {
            return (string) $explicit;
        }
        if ($invoice->invoiceRefNo !== '') {
            return $invoice->invoiceRefNo;
        }

        return 'USIN-'.strtoupper(substr($invoice->key(), 0, 12));
    }

    /** PRA accepts `yyyy-MM-dd HH:mm:ss`. Accept a date-only invoice and stamp a time. */
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

    protected function buyerName(?object $buyer): string
    {
        $name = $buyer?->name ?? '';

        return $name && strcasecmp($name, 'Walk-in Customer') !== 0 ? $name : '';
    }

    protected function itemCode(LineItem $item, int $index): string
    {
        // A real POS supplies its own SKU; synthesise a stable one when absent.
        if ($item->sroItemSerialNo) {
            return (string) $item->sroItemSerialNo;
        }

        return 'ITEM-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
    }
}
