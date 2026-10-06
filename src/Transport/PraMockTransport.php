<?php

namespace Nosh\OmniTax\Transport;

use Nosh\OmniTax\Contracts\Transport;

/**
 * An in-package fake PRA (PRAL) Software Fiscal Device. Returns responses in
 * PRA's EXACT documented shape — `{InvoiceNumber, Code:"100", Response, Errors}`
 * on success, a non-100 Code on failure, and `["Service is responding"]` for the
 * offline component's health GET — and runs PRA's core validations so the whole
 * flow is testable with NO token, NO IP whitelisting and NO network, for both
 * cloud and offline modes.
 *
 * Flip transport to 'http' and the identical PraDriver talks to the real PRA
 * cloud (ims.pral.com.pk) or the local IMS component (localhost:8524) — zero
 * code changes.
 */
class PraMockTransport implements Transport
{
    /** @var array<int,array{url:string,payload:array}> */
    public array $recorded = [];

    /** @var array<string,bool> USINs already accepted, for duplicate detection */
    protected array $seen = [];

    public function post(string $url, array $payload, array $headers = []): array
    {
        $this->recorded[] = ['url' => $url, 'payload' => $payload];

        $isCloud = str_contains($url, 'ims.pral.com.pk') || str_contains($url, '/Live/PostData');

        // Cloud requires a Bearer token (offline component needs none).
        if ($isCloud && (empty($headers['Authorization']) || trim($headers['Authorization']) === 'Bearer')) {
            return ['status' => 401, 'body' => ['Message' => 'Unauthorized']];
        }

        // Required header fields.
        $required = ['POSID', 'USIN', 'DateTime', 'TotalSaleValue', 'TotalTaxCharged', 'TotalBillAmount', 'PaymentMode', 'InvoiceType'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                return $this->fail('Required field missing: '.$key);
            }
        }
        if (empty($payload['Items'])) {
            return $this->fail('Invoice must contain at least one item.');
        }
        if ((int) $payload['POSID'] <= 0) {
            return $this->fail('POS ID is not registered.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', (string) $payload['DateTime'])) {
            return $this->fail('Invalid DateTime format. Use yyyy-MM-dd HH:mm:ss.');
        }
        if (! in_array((int) $payload['InvoiceType'], [1, 2, 3], true)) {
            return $this->fail('Invalid InvoiceType. Use 1 New, 2 Debit or 3 Credit.');
        }
        if ((float) $payload['TotalSaleValue'] <= 0) {
            return $this->fail('TotalSaleValue must be greater than zero.');
        }

        // Bill arithmetic: TotalBillAmount = TotalSaleValue + TotalTaxCharged + FurtherTax.
        $expectedBill = round((float) $payload['TotalSaleValue'] + (float) $payload['TotalTaxCharged'] + (float) ($payload['FurtherTax'] ?? 0), 2);
        if (abs($expectedBill - (float) $payload['TotalBillAmount']) > 0.01) {
            return $this->fail('TotalBillAmount does not equal TotalSaleValue + TotalTaxCharged + FurtherTax.');
        }

        // Duplicate USIN.
        $usin = (string) $payload['USIN'];
        if (isset($this->seen[$usin])) {
            return $this->fail('Duplicate USIN — this invoice was already fiscalised.');
        }
        $this->seen[$usin] = true;

        $invoiceNumber = $this->fakeInvoiceNumber($payload);

        return ['status' => 200, 'body' => [
            'InvoiceNumber' => $invoiceNumber,
            'Code'          => '100',
            'Response'      => 'Fiscal Invoice Number generated successfully.',
            'Errors'        => null,
        ]];
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        // The offline IMS component's health endpoint.
        if (str_contains($url, 'IMSFiscal/Get')) {
            return ['status' => 200, 'body' => ['Service is responding']];
        }

        return ['status' => 200, 'body' => []];
    }

    protected function fail(string $message): array
    {
        return ['status' => 200, 'body' => [
            'InvoiceNumber' => null,
            'Code'          => '101',
            'Response'      => $message,
            'Errors'        => $message,
        ]];
    }

    /** Shape mirrors a real PRA number, e.g. "9000052011142444901". */
    protected function fakeInvoiceNumber(array $payload): string
    {
        $posId = (string) ((int) $payload['POSID']);
        $stamp = date('YmdHis');
        $suffix = str_pad((string) (abs(crc32((string) $payload['USIN'])) % 1000), 3, '0', STR_PAD_LEFT);

        return $posId.$stamp.$suffix;
    }
}
