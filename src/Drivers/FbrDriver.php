<?php

namespace Nosh\OmniTax\Drivers;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Data\LineItem;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Responses\ItemStatus;

/**
 * FBR (Federal / PRAL) Digital Invoicing driver.
 *
 * Maps the canonical Invoice → FBR's postinvoicedata / validateinvoicedata
 * payload (per PRAL Digital Invoicing API v1.12) and parses FBR's response
 * back into a neutral FiscalResponse.
 */
class FbrDriver extends AbstractDriver
{
    public function key(): string
    {
        return 'fbr';
    }

    public function validate(Invoice $invoice): FiscalResponse
    {
        return $this->call($this->url('validate'), $invoice);
    }

    public function submit(Invoice $invoice): FiscalResponse
    {
        return $this->call($this->url('submit'), $invoice);
    }

    public function reference(string $type): array
    {
        $base = rtrim($this->config['urls']['reference'] ?? '', '/');
        $map = [
            'provinces' => 'provinces',
            'units'     => 'uom',
            'hsCodes'   => 'itemdesccode',
            'doctypes'  => 'doctypecode',
            'taxRates'  => 'SaleTypeToRate',
        ];
        $path = $map[$type] ?? $type;

        $result = $this->transport->get($base.'/'.$path, [], $this->headers());

        return $result['body'] ?? [];
    }

    protected function call(string $url, Invoice $invoice): FiscalResponse
    {
        $payload = $this->mapInvoice($invoice);
        $result = $this->transport->post($url, $payload, $this->headers());

        return $this->parse($result['status'] ?? 0, $result['body'] ?? []);
    }

    /**
     * Canonical Invoice → FBR payload.
     */
    public function mapInvoice(Invoice $invoice): array
    {
        $seller = $invoice->seller;
        $buyer = $invoice->buyer;

        $payload = [
            'invoiceType'          => $invoice->type,
            'invoiceDate'          => $invoice->date,
            'sellerNTNCNIC'        => $seller?->ntncnic ?? '',
            'sellerBusinessName'   => $seller?->name ?? '',
            'sellerProvince'       => $seller?->province ?? '',
            'sellerAddress'        => $seller?->address ?? '',
            'buyerNTNCNIC'         => $buyer?->ntncnic ?? '',
            'buyerBusinessName'    => $buyer?->name ?? '',
            'buyerProvince'        => $buyer?->province ?? '',
            'buyerAddress'         => $buyer?->address ?? '',
            'buyerRegistrationType'=> $buyer?->registrationType ?? 'Unregistered',
            'invoiceRefNo'         => $invoice->invoiceRefNo,
            'items'                => array_map(fn (LineItem $i) => $this->mapItem($i), $invoice->items),
        ];

        // scenarioId is sandbox-only.
        if ($this->credentials->sandbox && $invoice->scenarioId) {
            $payload['scenarioId'] = $invoice->scenarioId;
        }

        return $payload;
    }

    protected function mapItem(LineItem $item): array
    {
        return [
            'hsCode'                          => $item->hsCode ?? '',
            'productDescription'              => $item->description,
            'rate'                            => $item->taxRate ?? '',
            'uoM'                             => $item->uom ?? '',
            'quantity'                        => round($item->quantity, 4),
            'totalValues'                     => $item->totalValue(),
            'valueSalesExcludingST'           => $item->valueExcludingTax(),
            'fixedNotifiedValueOrRetailPrice' => $item->fixedNotifiedValueOrRetailPrice,
            'salesTaxApplicable'              => $item->computedTaxAmount(),
            'salesTaxWithheldAtSource'        => $item->salesTaxWithheldAtSource,
            'extraTax'                        => $item->extraTax,
            'furtherTax'                      => $item->furtherTax,
            'sroScheduleNo'                   => $item->sroScheduleNo ?? '',
            'fedPayable'                      => $item->fedPayable,
            'discount'                        => $item->discount,
            'saleType'                        => $item->saleType,
            'sroItemSerialNo'                 => $item->sroItemSerialNo ?? '',
        ];
    }

    /**
     * FBR response → neutral FiscalResponse.
     */
    public function parse(int $httpStatus, array $body): FiscalResponse
    {
        if ($httpStatus === 401) {
            return new FiscalResponse(
                valid: false,
                errors: ['0401 – Unauthorized (invalid or expired token)'],
                raw: $body,
                httpStatus: 401,
            );
        }

        $vr = $body['validationResponse'] ?? [];
        $statusCode = $vr['statusCode'] ?? null;
        $status = $vr['status'] ?? null;
        $valid = $statusCode === '00'
            && strtolower((string) $status) === 'valid'
            && $httpStatus === 200;

        $itemStatuses = [];
        $errors = [];

        if (! empty($vr['error'])) {
            $errors[] = trim(($vr['errorCode'] ?? '').' – '.$vr['error'], ' –');
        }

        foreach (($vr['invoiceStatuses'] ?? []) ?: [] as $s) {
            $status = ItemStatus::fromArray($s);
            $itemStatuses[] = $status;
            if (! $status->isValid()) {
                $valid = false;
                if ($status->error) {
                    $errors[] = "itemSNo {$status->itemSNo} → ".trim(($status->errorCode ?? '').' – '.$status->error, ' –');
                }
            }
        }

        return new FiscalResponse(
            valid: $valid,
            invoiceNumber: $body['invoiceNumber'] ?? null,
            dated: $body['dated'] ?? null,
            statusCode: $statusCode,
            status: $vr['status'] ?? null,
            errors: $errors,
            itemStatuses: $itemStatuses,
            raw: $body,
            httpStatus: $httpStatus,
        );
    }
}
