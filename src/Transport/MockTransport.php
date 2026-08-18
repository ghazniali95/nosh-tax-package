<?php

namespace Nosh\OmniTax\Transport;

use Nosh\OmniTax\Contracts\Transport;

/**
 * An in-package fake authority. Returns responses in FBR's EXACT documented
 * shape so the whole flow is testable with NO token and NO network.
 *
 * It performs the same basic validations FBR does (missing rate, missing HS
 * code for goods, unregistered seller marker) so rejections look real too.
 *
 * The moment a real token arrives you switch transport back to 'http' and the
 * identical driver code talks to FBR's real _sb sandbox — zero code changes.
 */
class MockTransport implements Transport
{
    /** @var array<int,array{url:string,payload:array}> */
    public array $recorded = [];

    public function post(string $url, array $payload, array $headers = []): array
    {
        $this->recorded[] = ['url' => $url, 'payload' => $payload];

        // Unauthorized when no bearer token present (mirrors real 401).
        if (empty($headers['Authorization']) || $headers['Authorization'] === 'Bearer ') {
            return ['status' => 401, 'body' => ['message' => 'Unauthorized']];
        }

        $isValidate = str_contains($url, 'validateinvoicedata');
        $items = $payload['items'] ?? [];

        [$itemStatuses, $errors] = $this->inspectItems($items, $isValidate);
        $allValid = $errors === [];

        // A header-level fatal (e.g. bad seller) short-circuits with statusCode 01.
        if (($payload['sellerNTNCNIC'] ?? '') === '') {
            return $this->response(false, null, [
                'statusCode' => '01',
                'status'     => 'Invalid',
                'errorCode'  => '0001',
                'error'      => 'Seller not registered for sales tax',
                'invoiceStatuses' => null,
            ]);
        }

        return $this->response(
            valid: $allValid,
            invoiceNumber: $allValid && ! $isValidate ? $this->fakeInvoiceNumber($payload) : ($allValid ? null : null),
            validationResponse: [
                'statusCode'      => $allValid ? '00' : '01',
                'status'          => $allValid ? 'Valid' : 'Invalid',
                'errorCode'       => $allValid ? '' : ($errors[0]['code'] ?? ''),
                'error'           => $allValid ? '' : ($errors[0]['message'] ?? ''),
                'invoiceStatuses' => $itemStatuses,
            ],
        );
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        // Minimal reference-data stubs mirroring FBR /pdi/v1/* shapes.
        if (str_contains($url, 'provinces')) {
            return ['status' => 200, 'body' => [
                ['stateProvinceCode' => 7, 'stateProvinceDesc' => 'PUNJAB'],
                ['stateProvinceCode' => 8, 'stateProvinceDesc' => 'SINDH'],
                ['stateProvinceCode' => 6, 'stateProvinceDesc' => 'KHYBER PAKHTUNKHWA'],
                ['stateProvinceCode' => 5, 'stateProvinceDesc' => 'BALOCHISTAN'],
                ['stateProvinceCode' => 1, 'stateProvinceDesc' => 'CAPITAL TERRITORY'],
            ]];
        }

        return ['status' => 200, 'body' => []];
    }

    protected function inspectItems(array $items, bool $isValidate): array
    {
        $statuses = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $sno = (string) ($index + 1);
            $itemErr = null;

            $isGoods = str_starts_with((string) ($item['saleType'] ?? ''), 'Goods');

            if (empty($item['rate'])) {
                $itemErr = ['code' => '0046', 'message' => 'Provide rate.'];
            } elseif ($isGoods && empty($item['hsCode'])) {
                $itemErr = ['code' => '0052', 'message' => 'Provide proper HS Code.'];
            }

            if ($itemErr) {
                $errors[] = $itemErr;
                $statuses[] = [
                    'itemSNo'    => $sno,
                    'statusCode' => '01',
                    'status'     => 'Invalid',
                    'invoiceNo'  => null,
                    'errorCode'  => $itemErr['code'],
                    'error'      => $itemErr['message'],
                ];
            } else {
                $statuses[] = [
                    'itemSNo'    => $sno,
                    'statusCode' => '00',
                    'status'     => 'Valid',
                    'invoiceNo'  => null,
                    'errorCode'  => '',
                    'error'      => '',
                ];
            }
        }

        return [$statuses, $errors];
    }

    protected function response(bool $valid, ?string $invoiceNumber, array $validationResponse): array
    {
        return [
            'status' => 200,
            'body'   => [
                'invoiceNumber'      => $invoiceNumber,
                'dated'              => date('Y-m-d H:i:s'),
                'validationResponse' => $validationResponse,
            ],
        ];
    }

    protected function fakeInvoiceNumber(array $payload): string
    {
        // Shape mirrors a real FBR number: {sellerdigits}DI{timestamp-ish}
        $seller = preg_replace('/\D/', '', (string) ($payload['sellerNTNCNIC'] ?? '7000007'));
        $seller = substr(str_pad($seller, 7, '0'), 0, 7);

        return $seller.'DI'.(string) (intval(microtime(true) * 1000));
    }
}
