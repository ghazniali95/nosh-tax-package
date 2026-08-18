<?php

namespace Nosh\OmniTax\Testing;

use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Data\Invoice;

/**
 * Ready-made, valid sandbox invoices for FBR's official test scenarios
 * (SN001–SN028). For a restaurant the relevant ones are the services
 * scenarios SN018 / SN019.
 */
class Scenario
{
    /** Which scenarios apply to a given business activity. */
    protected const BY_ACTIVITY = [
        'services'   => ['SN018', 'SN019'],
        'goods'      => ['SN001', 'SN002'],
        'restaurant' => ['SN019'],
    ];

    /** Build a ready, valid sandbox invoice for a scenario id. */
    public static function make(string $scenarioId): Invoice
    {
        return match ($scenarioId) {
            'SN019' => static::servicesSale($scenarioId),
            'SN018' => static::servicesFedInStMode($scenarioId),
            default => static::genericGoodsSale($scenarioId),
        };
    }

    /** @return string[] */
    public static function forBusinessActivity(string $activity): array
    {
        return self::BY_ACTIVITY[strtolower($activity)] ?? [];
    }

    /** SN019 — Sale of Services (normal restaurant dine-in). */
    protected static function servicesSale(string $scenarioId): Invoice
    {
        return (new InvoiceBuilder())
            ->type('Sale Invoice')
            ->date(date('Y-m-d'))
            ->seller('0786909', 'Nosh Test Restaurant', 'Sindh', 'Karachi')
            ->walkInCustomer('Sindh')
            ->scenario($scenarioId)
            ->addItem(
                (new LineItemBuilder())
                    ->description('Chicken Karahi (Full)')
                    ->quantity(1)->unitPrice(1800.00)
                    ->taxRate('16%')->saleType('Services')
                    ->uom('Numbers, pieces, units')
            )
            ->build();
    }

    /** SN018 — Services where FED is charged in ST mode. */
    protected static function servicesFedInStMode(string $scenarioId): Invoice
    {
        return (new InvoiceBuilder())
            ->type('Sale Invoice')
            ->date(date('Y-m-d'))
            ->seller('0786909', 'Nosh Test Restaurant', 'Sindh', 'Karachi')
            ->walkInCustomer('Sindh')
            ->scenario($scenarioId)
            ->addItem(
                (new LineItemBuilder())
                    ->description('Banquet Service Charge')
                    ->quantity(1)->unitPrice(5000.00)
                    ->taxRate('16%')->saleType('Services (FED in ST Mode)')
                    ->uom('Numbers, pieces, units')
            )
            ->build();
    }

    /** A generic goods sale for the remaining scenarios. */
    protected static function genericGoodsSale(string $scenarioId): Invoice
    {
        return (new InvoiceBuilder())
            ->type('Sale Invoice')
            ->date(date('Y-m-d'))
            ->seller('0786909', 'Nosh Test Seller', 'Sindh', 'Karachi')
            ->walkInCustomer('Sindh')
            ->scenario($scenarioId)
            ->addItem(
                (new LineItemBuilder())
                    ->description('Sample Product')
                    ->quantity(1)->unitPrice(1000.00)
                    ->taxRate('18%')->saleType('Goods at standard rate (default)')
                    ->hsCode('0101.2100')
                    ->uom('Numbers, pieces, units')
            )
            ->build();
    }
}
