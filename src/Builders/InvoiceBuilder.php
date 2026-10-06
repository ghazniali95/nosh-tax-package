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

    /**
     * Make this a credit note (refund / sales return) against an earlier sale.
     *
     * `$originalNumber` is YOUR number for the original sale (PRA `RefUSIN`);
     * `$originalFiscalNumber` is the number the authority issued for it, kept
     * as the invoice reference. Give the credit note its own `->number()` too —
     * it is a new document, not a resubmission of the old one.
     *
     * Not every authority accepts one: check `supports(Feature::CREDIT_NOTE)`
     * (FBR Digital Invoicing has no credit note). An unsupported authority
     * answers with a clear rejection rather than a silent no-op.
     */
    public function creditNoteFor(string $originalNumber, ?string $originalFiscalNumber = null): self
    {
        $this->invoice->type = 'Credit Note';
        $this->invoice->invoiceRefNo = $originalFiscalNumber ?? $originalNumber;

        return $this->meta(['refUsin' => $originalNumber]);
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

    // ---- Invoice-level fields used by SRB (stored in meta; other drivers -----
    // ---- read line items, so these are harmless no-ops for them). -----------

    /** Our own unique invoice number (SRB `invoiceId`; must be unique per sale). */
    public function number(string $invoiceId): self
    {
        return $this->meta(['invoiceId' => $invoiceId]);
    }

    /** Full invoice timestamp (SRB requires `yyyy-MM-dd HH:mm:ss`). */
    public function at(DateTimeInterface|string $dateTime): self
    {
        $value = $dateTime instanceof DateTimeInterface
            ? $dateTime->format('Y-m-d H:i:s')
            : $dateTime;

        return $this->meta(['invoiceDateTime' => $value]);
    }

    /** Invoice-level service charges (SRB `serviceCharges`). */
    public function serviceCharges(float $amount): self
    {
        return $this->meta(['serviceCharges' => $amount]);
    }

    /** Invoice-level extra charges (SRB `extraCharges`). */
    public function extraCharges(float $amount): self
    {
        return $this->meta(['extraCharges' => $amount]);
    }

    /** Invoice-level discount (SRB `discountAmount`). */
    public function discountAmount(float $amount): self
    {
        return $this->meta(['discountAmount' => $amount]);
    }

    /** Payment mode printed on the receipt (SRB `modeOfPay`: Cash/Card). */
    public function modeOfPay(string $mode): self
    {
        return $this->meta(['modeOfPay' => $mode]);
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
