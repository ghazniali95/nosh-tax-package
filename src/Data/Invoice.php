<?php

namespace Nosh\OmniTax\Data;

/**
 * The authority-neutral canonical invoice. Your app always builds THIS;
 * the driver maps it to a specific authority's payload.
 */
class Invoice
{
    /** @param LineItem[] $items */
    public function __construct(
        public string $type = 'Sale Invoice',
        public ?string $date = null,             // "Y-m-d"
        public ?Seller $seller = null,
        public ?Buyer $buyer = null,
        public string $invoiceRefNo = '',
        public array $items = [],
        public ?string $scenarioId = null,        // sandbox only
        public ?string $idempotencyKey = null,    // stable per-sale key
        public array $meta = [],
    ) {
        $this->date ??= date('Y-m-d');
        $this->buyer ??= Buyer::walkIn();
    }

    /** @param LineItem[] $items */
    public function withItems(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    public function subtotalExcludingTax(): float
    {
        return round(array_sum(array_map(fn (LineItem $i) => $i->valueExcludingTax(), $this->items)), 2);
    }

    public function totalTax(): float
    {
        return round(array_sum(array_map(fn (LineItem $i) => $i->computedTaxAmount(), $this->items)), 2);
    }

    public function grandTotal(): float
    {
        return round(array_sum(array_map(fn (LineItem $i) => $i->totalValue(), $this->items)), 2);
    }

    /**
     * A stable idempotency key for this sale, so retries / double-clicks
     * never report the same invoice twice.
     *
     * Precedence: an explicit key, then YOUR invoice number (`->number()`), then
     * a hash of the content. The number is what makes a sale unique — before
     * v1.2 it was left out, so two identical sales on one day (two walk-ins
     * each buying one burger) hashed to the same key and the second was merged
     * into the first and never reported. Always give a sale its number; the
     * content hash is a fallback, and it now includes the timestamp, buyer and
     * meta so it is only as collision-prone as the data you leave out.
     */
    public function key(): string
    {
        if ($this->idempotencyKey) {
            return $this->idempotencyKey;
        }

        $number = $this->meta['invoiceId'] ?? $this->meta['usin'] ?? null;

        if ($number !== null && $number !== '') {
            return $this->idempotencyKey = hash('sha256', json_encode([
                'v2', $this->type, $this->seller?->ntncnic, (string) $number, $this->invoiceRefNo,
            ]));
        }

        return $this->idempotencyKey = hash('sha256', json_encode([
            'v2',
            $this->type,
            $this->date,
            $this->seller?->ntncnic,
            $this->buyer?->ntncnic,
            $this->invoiceRefNo,
            $this->meta,
            array_map(fn (LineItem $i) => $i->toArray(), $this->items),
        ]));
    }

    /** Is this a credit note / sales return (refund) rather than a sale? */
    public function isCreditNote(): bool
    {
        $t = strtolower($this->type);

        return str_contains($t, 'credit') || str_contains($t, 'return') || str_contains($t, 'refund');
    }

    public function toArray(): array
    {
        return [
            'type'         => $this->type,
            'date'         => $this->date,
            'seller'       => $this->seller?->toArray(),
            'buyer'        => $this->buyer?->toArray(),
            'invoiceRefNo' => $this->invoiceRefNo,
            'scenarioId'   => $this->scenarioId,
            'idempotencyKey' => $this->key(),
            'items'        => array_map(fn (LineItem $i) => $i->toArray(), $this->items),
            'totals'       => [
                'excludingTax' => $this->subtotalExcludingTax(),
                'tax'          => $this->totalTax(),
                'grand'        => $this->grandTotal(),
            ],
            'meta'         => $this->meta,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            type: $d['type'] ?? 'Sale Invoice',
            date: $d['date'] ?? null,
            seller: isset($d['seller']) && $d['seller'] ? Seller::fromArray($d['seller']) : null,
            buyer: isset($d['buyer']) && $d['buyer'] ? Buyer::fromArray($d['buyer']) : null,
            invoiceRefNo: $d['invoiceRefNo'] ?? '',
            items: array_map(fn ($i) => LineItem::fromArray($i), $d['items'] ?? []),
            scenarioId: $d['scenarioId'] ?? null,
            idempotencyKey: $d['idempotencyKey'] ?? null,
            meta: $d['meta'] ?? [],
        );
    }
}
