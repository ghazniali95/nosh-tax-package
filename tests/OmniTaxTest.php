<?php

namespace Nosh\OmniTax\Tests;

use Illuminate\Support\Facades\Event;
use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Seller;
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

    /** PRA and SRB ship now; with a registered POS each returns a fiscal number from the mock. */
    public function test_pra_and_srb_submit_through_the_mock_authority(): void
    {
        $credentials = [
            'pra' => ['token' => 'test-token', 'posId' => '1234'],
            'srb' => ['posId' => '1234', 'posUser' => 'pos', 'posPass' => 'secret'],
        ];

        // The suite binds the FBR mock for everything; drop it so each
        // authority gets its own mock (transport = mock in config).
        $this->app->offsetUnset(\Nosh\OmniTax\Contracts\Transport::class);

        foreach ($credentials as $authority => $creds) {
            OmniTax::resolveCredentialsUsing(fn () => new Credentials(...$creds + [
                'authority' => $authority, 'sandbox' => true, 'mode' => 'cloud',
                'seller' => new Seller('1234567', 'Test Restaurant', 'Punjab', 'Lahore'),
            ]));

            $response = OmniTax::authority($authority)->submit($this->restaurantInvoice());

            $this->assertTrue($response->isValid(), "{$authority} accepted: ".json_encode($response->errors()));
            $this->assertNotNull($response->invoiceNumber(), "{$authority} fiscal number");
        }
    }

    /** Without a registered POS the provincial drivers refuse rather than guess. */
    public function test_pra_without_a_pos_id_is_refused(): void
    {
        $response = OmniTax::authority('pra')->submit($this->restaurantInvoice());

        $this->assertFalse($response->isValid());
        $this->assertStringContainsString('POS ID', implode(' ', $response->errors()));
    }

    /** An authority whose driver has not shipped still refuses clearly. */
    public function test_an_authority_still_rolling_out_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/rolling out|not yet available/i');
        OmniTax::authority('kpra')->submit($this->restaurantInvoice());
    }
}
