<?php

namespace Nosh\OmniTax\Builders;

use DateTimeInterface;
use Nosh\OmniTax\Data\Buyer;
use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Data\LineItem;
use Nosh\OmniTax\Data\Seller;

/**
 * Fluent builder for a canonical invoice.
 *
 *   (new InvoiceBuilder())
 *       ->type('Sale Invoice')->date(now())
 *       ->seller('0786909', 'Karachi Grill House', 'Sindh', 'Karachi')
 *       ->walkInCustomer()
 *       ->addItem($karahi)->addItem($naan)
 *       ->build();
 */
class InvoiceBuilder
{
    protected Invoice $invoice;

    public function __construct()
    {
        $this->invoice = new Invoice();
    }

    public static function make(): self
    {
        return new self();
    }

    public function type(string $value): self
    {
        $this->invoice->type = $value;

        return $this;
    }

    public function date(DateTimeInterface|string $value): self
    {
        $this->invoice->date = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : $value;

        return $this;
    }

    public function seller(string $ntncnic, string $name, string $province, string $address): self
    {
        $this->invoice->seller = new Seller($ntncnic, $name, $province, $address);

        return $this;
    }

    public function sellerFrom(Seller $seller): self
    {
        $this->invoice->seller = $seller;

        return $this;
    }

    public function buyer(string $ntncnic, string $name, string $province, ?string $address = null): self
    {
        $this->invoice->buyer = new Buyer($ntncnic, $name, $province, $address, Buyer::REGISTERED);

        return $this;
    }

    public function walkInCustomer(?string $province = null): self
    {
        $this->invoice->buyer = Buyer::walkIn($province);

        return $this;
    }

    public function invoiceRefNo(string $value): self
    {
        $this->invoice->invoiceRefNo = $value;

        return $this;
    }

    public function scenario(string $id): self
    {
        $this->invoice->scenarioId = $id;

        return $this;
    }

    public function idempotencyKey(string $key): self
    {
        $this->invoice->idempotencyKey = $key;

        return $this;
    }

    public function meta(array $meta): self
    {
        $this->invoice->meta = array_merge($this->invoice->meta, $meta);

        return $this;
    }

    public function addItem(LineItem|LineItemBuilder $item): self
    {
        $this->invoice->items[] = $item instanceof LineItemBuilder ? $item->build() : $item;

        return $this;
    }

    /** @param array<LineItem|LineItemBuilder> $items */
    public function items(array $items): self
    {
        foreach ($items as $item) {
            $this->addItem($item);
        }

        return $this;
    }

    public function build(): Invoice
    {
        return $this->invoice;
    }
}
