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
     */
    public function key(): string
    {
        if ($this->idempotencyKey) {
            return $this->idempotencyKey;
        }

        return $this->idempotencyKey = hash('sha256', json_encode([
            $this->type,
            $this->date,
            $this->seller?->ntncnic,
            $this->invoiceRefNo,
            array_map(fn (LineItem $i) => $i->toArray(), $this->items),
        ]));
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
