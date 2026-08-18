<?php

namespace Nosh\OmniTax\Data;

/**
 * One authority-neutral line on an invoice (a dish, product, or service).
 *
 * The neutral vocabulary here is deliberately human: description / quantity /
 * unitPrice / taxRate. Each driver maps it to the authority's field names
 * (for FBR: productDescription / quantity / valueSalesExcludingST / rate ...).
 *
 * Tax amounts are computed by the builder when not supplied.
 */
class LineItem
{
    public function __construct(
        public string $description = '',
        public float $quantity = 1.0,
        public float $unitPrice = 0.0,          // value per unit, excluding tax
        public ?string $taxRate = null,          // e.g. "16%"
        public ?float $taxAmount = null,         // computed if null
        public string $saleType = 'Services',    // "Services" for restaurants
        public ?string $hsCode = null,           // required by FBR for goods
        public ?string $uom = null,              // unit of measure
        public float $furtherTax = 0.0,
        public float $extraTax = 0.0,
        public float $discount = 0.0,
        public float $fedPayable = 0.0,
        public float $salesTaxWithheldAtSource = 0.0,
        public float $fixedNotifiedValueOrRetailPrice = 0.0,
        public ?string $sroScheduleNo = null,
        public ?string $sroItemSerialNo = null,
    ) {
    }

    /** Value of the line before tax (unitPrice × quantity − discount). */
    public function valueExcludingTax(): float
    {
        return round(($this->unitPrice * $this->quantity) - $this->discount, 2);
    }

    /** Numeric tax rate parsed from "16%" → 0.16. Null when no rate set. */
    public function rateFraction(): ?float
    {
        if ($this->taxRate === null || $this->taxRate === '') {
            return null;
        }

        return ((float) rtrim($this->taxRate, '% ')) / 100;
    }

    /** Computed tax amount for the line (explicit taxAmount wins). */
    public function computedTaxAmount(): float
    {
        if ($this->taxAmount !== null) {
            return round($this->taxAmount, 2);
        }

        $fraction = $this->rateFraction();

        return $fraction === null ? 0.0 : round($this->valueExcludingTax() * $fraction, 2);
    }

    /** Line total including tax and further/extra taxes. */
    public function totalValue(): float
    {
        return round(
            $this->valueExcludingTax()
            + $this->computedTaxAmount()
            + $this->furtherTax
            + $this->extraTax
            + $this->fedPayable,
            2
        );
    }

    public function toArray(): array
    {
        return [
            'description'                     => $this->description,
            'quantity'                        => $this->quantity,
            'unitPrice'                       => $this->unitPrice,
            'valueExcludingTax'               => $this->valueExcludingTax(),
            'taxRate'                         => $this->taxRate,
            'taxAmount'                       => $this->computedTaxAmount(),
            'totalValue'                      => $this->totalValue(),
            'saleType'                        => $this->saleType,
            'hsCode'                          => $this->hsCode,
            'uom'                             => $this->uom,
            'furtherTax'                      => $this->furtherTax,
            'extraTax'                        => $this->extraTax,
            'discount'                        => $this->discount,
            'fedPayable'                      => $this->fedPayable,
            'salesTaxWithheldAtSource'        => $this->salesTaxWithheldAtSource,
            'fixedNotifiedValueOrRetailPrice' => $this->fixedNotifiedValueOrRetailPrice,
            'sroScheduleNo'                   => $this->sroScheduleNo,
            'sroItemSerialNo'                 => $this->sroItemSerialNo,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            description: $d['description'] ?? '',
            quantity: (float) ($d['quantity'] ?? 1),
            unitPrice: (float) ($d['unitPrice'] ?? $d['valueExcludingTax'] ?? 0),
            taxRate: $d['taxRate'] ?? null,
            taxAmount: isset($d['taxAmount']) ? (float) $d['taxAmount'] : null,
            saleType: $d['saleType'] ?? 'Services',
            hsCode: $d['hsCode'] ?? null,
            uom: $d['uom'] ?? null,
            furtherTax: (float) ($d['furtherTax'] ?? 0),
            extraTax: (float) ($d['extraTax'] ?? 0),
            discount: (float) ($d['discount'] ?? 0),
            fedPayable: (float) ($d['fedPayable'] ?? 0),
            salesTaxWithheldAtSource: (float) ($d['salesTaxWithheldAtSource'] ?? 0),
            fixedNotifiedValueOrRetailPrice: (float) ($d['fixedNotifiedValueOrRetailPrice'] ?? 0),
            sroScheduleNo: $d['sroScheduleNo'] ?? null,
            sroItemSerialNo: $d['sroItemSerialNo'] ?? null,
        );
    }
}
