<?php

namespace Nosh\OmniTax\Transport;

use Nosh\OmniTax\Contracts\Transport;

/**
 * An in-package fake SRB POS authority. Returns responses in SRB's EXACT
 * documented shape ({srbInvoiceId, resCode, QRCodeLink} on success; the
 * §6 error bodies on failure) and runs the same validations SRB documents
 * (§9) — required fields, POS ID, date format, invoice type, the tax and
 * net-amount formulae, and duplicate invoiceId detection — so the whole
 * flow is testable with NO POS ID and NO network, for both cloud and
 * offline modes.
 *
 * Flip transport back to 'http' and the identical SrbDriver talks to the
 * real SRB gateway (cloud) or local Connector (offline) — zero code changes.
 */
class SrbMockTransport implements Transport
{
    /** @var array<int,array{url:string,payload:array}> */
    public array $recorded = [];

    /** @var array<string,bool> invoiceIds already accepted, for duplicate detection */
    protected array $seen = [];

    public function post(string $url, array $payload, array $headers = []): array
    {
        $this->recorded[] = ['url' => $url, 'payload' => $payload];

        $isCloud = str_contains($url, 'ePOSGateway') || str_contains($url, 'srb.gos.pk');

        // 6.1 — required parameters present?
        $required = [
            'posId', 'name', 'ntn', 'invoiceDateTime', 'invoiceType', 'invoiceId',
            'rateValue', 'saleValue', 'taxAmount', 'netAmount', 'modeOfPay', 'transType',
        ];
        $missing = [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                $missing[] = $key;
            }
        }
        if ($missing) {
            return $this->body([
                'status' => 'BAD_REQUEST',
                'resCode' => '01',
                'error' => 'Error in Validation',
                'Required Parameter(s) Missing' => implode(', ', $missing),
            ]);
        }

        // Cloud gateway also needs the posUser/posPass credentials.
        if ($isCloud && (empty($payload['posUser']) || empty($payload['posPass']))) {
            return $this->fail('02', 'Invalid POS credentials (posUser/posPass).');
        }

        // 6.2 — POS ID registered? (a non-positive id is treated as unregistered)
        if ((int) $payload['posId'] <= 0) {
            return $this->fail('02', 'POS ID is not register');
        }

        // 6.5 — date format
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $payload['invoiceDateTime'])) {
            return $this->fail('02', 'Invalid Date Time Format is used for invoiceDateTime. It must be in yyyy-MM-dd HH:mm:ss format');
        }

        // 6.6 — invoice type
        if (! in_array((int) $payload['invoiceType'], [1, 2], true)) {
            return $this->fail('02', 'Invalid Invoice Type. Use 1 for Normal Invoice or 2 for Sales Return');
        }

        // §9.5/9.6 — sale value must be positive
        if ((float) $payload['saleValue'] <= 0) {
            return $this->fail('02', 'Invalid Net Amount is used.');
        }

        // 6.7 — tax must match the formula
        $base = (float) $payload['saleValue'] + (float) ($payload['serviceCharges'] ?? 0) + (float) ($payload['extraCharges'] ?? 0);
        $expectedTax = round($base * ((float) $payload['rateValue'] / 100), 2);
        if (abs($expectedTax - (float) $payload['taxAmount']) > 0.01) {
            return $this->fail('02', 'Tax amount is not calculated as per rate and sales value.');
        }

        // 6.8 — net amount must match the formula
        $expectedNet = round($base + (float) $payload['taxAmount'] - (float) ($payload['discountAmount'] ?? 0), 2);
        if (abs($expectedNet - (float) $payload['netAmount']) > 0.01) {
            return $this->fail('02', 'Invalid Net Amount is used.');
        }

        // 6.9 — duplicate invoiceId
        $invoiceId = (string) $payload['invoiceId'];
        if (isset($this->seen[$invoiceId])) {
            return $this->body([
                'status' => 'Fail',
                'resCode' => '02',
                'error' => 'Sales invoice cannot be entered due to duplicate InvoiceId.',
            ]);
        }
        $this->seen[$invoiceId] = true;

        // Success — SRB invoice number + verification URL for the QR.
        $srbInvoiceId = $this->fakeInvoiceNumber($payload);

        return $this->body([
            'srbInvoiceId' => $srbInvoiceId,
            'resCode' => '00',
            'QRCodeLink' => 'https://apps.srb.gos.pk/InvoiceVerification/MobileInvoiceStatus.jsp?invoiceVerification='.$srbInvoiceId,
            'dated' => date('Y-m-d H:i:s'),
        ]);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        // SRB publishes no server-to-server reference data.
        return ['status' => 200, 'body' => []];
    }

    protected function fail(string $resCode, string $error): array
    {
        return $this->body([
            'status' => 'BAD_REQUEST',
            'resCode' => $resCode,
            'error' => $error,
        ]);
    }

    protected function body(array $body): array
    {
        return ['status' => 200, 'body' => $body];
    }

    /** Shape mirrors a real SRB number, e.g. "33126066A22" = posId + digits + suffix. */
    protected function fakeInvoiceNumber(array $payload): string
    {
        $posId = (string) ((int) $payload['posId']);
        $stamp = date('ym');
        $suffix = strtoupper(substr(md5((string) $payload['invoiceId']), 0, 3));

        return $posId.$stamp.'6'.$suffix;
    }
}
