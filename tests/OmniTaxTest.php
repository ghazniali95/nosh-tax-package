<?php

namespace Nosh\OmniTax\Tests;

use Illuminate\Support\Facades\Event;
use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Events\InvoiceAccepted;
use Nosh\OmniTax\Facades\OmniTax;
use Nosh\OmniTax\Models\FiscalInvoice;
use Nosh\OmniTax\Testing\Scenario;

class OmniTaxTest extends TestCase
{
    protected function restaurantInvoice()
    {
        return (new InvoiceBuilder())
            ->type('Sale Invoice')->date('2026-08-18')
            ->walkInCustomer('Sindh')->scenario('SN019')
            ->addItem((new LineItemBuilder())
                ->description('Chicken Karahi (Full)')->quantity(1)->unitPrice(1800)
                ->taxRate('16%')->saleType('Services'))
            ->build();
    }

    public function test_submit_returns_a_fiscal_number(): void
    {
        $response = OmniTax::submit($this->restaurantInvoice());

        $this->assertTrue($response->isValid());
        $this->assertNotNull($response->invoiceNumber());
        $this->assertStringContainsString('DI', $response->invoiceNumber());
        $this->assertSame($response->invoiceNumber(), $response->qr()->payload());
    }

    public function test_seller_falls_back_to_config_on_submit(): void
    {
        // Invoice built with no seller — the manager fills it from config credentials.
        $response = OmniTax::submit($this->restaurantInvoice());
        $this->assertTrue($response->isValid());
    }

    public function test_events_fire_on_accept(): void
    {
        Event::fake([InvoiceAccepted::class]);
        OmniTax::submit($this->restaurantInvoice());
        Event::assertDispatched(InvoiceAccepted::class);
    }

    public function test_scenario_builder_makes_a_services_invoice(): void
    {
        $invoice = Scenario::make('SN019');
        $this->assertSame('Services', $invoice->items[0]->saleType);
        $this->assertContains('SN019', Scenario::forBusinessActivity('restaurant'));
    }

    public function test_background_record_is_idempotent(): void
    {
        $invoice = $this->restaurantInvoice();
        $a = FiscalInvoice::fromInvoice($invoice);
        $b = FiscalInvoice::fromInvoice($invoice);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(FiscalInvoice::PENDING, $a->status);
    }

    public function test_provincial_authority_not_yet_available(): void
    {
        $this->expectExceptionMessageMatches('/rolling out|not yet available/i');
        OmniTax::authority('pra')->submit($this->restaurantInvoice());
    }
}
