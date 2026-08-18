<?php

namespace Nosh\OmniTax\Builders;

use Nosh\OmniTax\Data\LineItem;

/**
 * Fluent builder for one invoice line.
 *
 *   (new LineItemBuilder())
 *       ->description('Chicken Karahi (Full)')
 *       ->quantity(1)->unitPrice(1800.00)
 *       ->taxRate('16%')->saleType('Services')
 *       ->build();
 */
class LineItemBuilder
{
    protected LineItem $item;

    public function __construct()
    {
        $this->item = new LineItem();
    }

    public static function make(): self
    {
        return new self();
    }

    public function description(string $value): self
    {
        $this->item->description = $value;

        return $this;
    }

    public function quantity(float $value): self
    {
        $this->item->quantity = $value;

        return $this;
    }

    public function unitPrice(float $value): self
    {
        $this->item->unitPrice = $value;

        return $this;
    }

    public function taxRate(string $value): self
    {
        // Accept "16", "16%", 16 → normalise to "16%"
        $this->item->taxRate = str_ends_with($value, '%') ? $value : $value.'%';

        return $this;
    }

    public function taxAmount(float $value): self
    {
        $this->item->taxAmount = $value;

        return $this;
    }

    public function saleType(string $value): self
    {
        $this->item->saleType = $value;

        return $this;
    }

    public function hsCode(string $value): self
    {
        $this->item->hsCode = $value;

        return $this;
    }

    public function uom(string $value): self
    {
        $this->item->uom = $value;

        return $this;
    }

    public function discount(float $value): self
    {
        $this->item->discount = $value;

        return $this;
    }

    public function furtherTax(float $value): self
    {
        $this->item->furtherTax = $value;

        return $this;
    }

    public function extraTax(float $value): self
    {
        $this->item->extraTax = $value;

        return $this;
    }

    public function fedPayable(float $value): self
    {
        $this->item->fedPayable = $value;

        return $this;
    }

    public function sro(string $scheduleNo, ?string $itemSerialNo = null): self
    {
        $this->item->sroScheduleNo = $scheduleNo;
        $this->item->sroItemSerialNo = $itemSerialNo;

        return $this;
    }

    public function build(): LineItem
    {
        return $this->item;
    }
}
