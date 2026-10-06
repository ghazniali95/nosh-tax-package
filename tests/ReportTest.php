<?php

namespace Nosh\OmniTax\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Contracts\TimeoutAware;
use Nosh\OmniTax\Contracts\Transport;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Data\Seller;
use Nosh\OmniTax\Facades\OmniTax;
use Nosh\OmniTax\Jobs\SubmitFiscalInvoice;
use Nosh\OmniTax\Models\FiscalInvoice;
use Nosh\OmniTax\Support\Feature;
use Nosh\OmniTax\Transport\HttpTransport;

/**
 * v1.2: real-time-or-queue reporting, the idempotency fix, retry semantics,
 * credit notes, feature checks and the connection check.
 */
class ReportTest extends TestCase
{
    private function sale(string $number, string $dish = 'Zinger Burger', float $price = 650): Invoice
    {
        return (new InvoiceBuilder())
            ->type('Sale Invoice')->date('2026-10-06')
            ->walkInCustomer('Sindh')->scenario('SN019')
            ->number($number)
            ->addItem((new LineItemBuilder())
                ->description($dish)->quantity(1)->unitPrice($price)
                ->taxRate('16%')->saleType('Services'))
            ->build();
    }

    /** Replace the suite's FBR mock with a fake that answers as told. */
    private function fakeTransport(int $status, array $body = []): object
    {
        $fake = new class($status, $body) implements Transport, TimeoutAware {
            public int $calls = 0;
            public ?float $timeout = null;

            public function __construct(private int $status, private array $body) {}

            public function withTimeout(float $seconds): static
            {
                $this->timeout = $seconds;

                return $this;
            }

            public function post(string $url, array $payload, array $headers = []): array
            {
                $this->calls++;

                return ['status' => $this->status, 'body' => $this->body];
            }

            public function get(string $url, array $query = [], array $headers = []): array
            {
                return ['status' => $this->status, 'body' => $this->body];
            }
        };

        $this->app->instance(Transport::class, $fake);

        return $fake;
    }

    private function usePerAuthorityMocks(): void
    {
        $this->app->offsetUnset(Transport::class);
    }

    private function praCredentials(): void
    {
        OmniTax::resolveCredentialsUsing(fn () => new Credentials(
            authority: 'pra', token: 'test-token', sandbox: true, posId: '1234', mode: 'cloud',
            seller: new Seller('1234567', 'Test Restaurant', 'Punjab', 'Lahore'),
        ));
    }

    // ── report(): real time first, queue as the fallback ──────────────────

    public function test_an_accepted_sale_is_valid_with_its_number_and_qr(): void
    {
        Queue::fake();

        $record = OmniTax::report($this->sale('B-1'), 'bill:1');

        $this->assertSame(FiscalInvoice::VALID, $record->status);
        $this->assertNotNull($record->fiscal_number);
        $this->assertSame(1, $record->attempts);
        $this->assertSame('bill:1', $record->reference);
        $this->assertNotNull($record->qr());
        Queue::assertNothingPushed();
    }

    public function test_an_unreachable_authority_queues_the_sale_and_does_not_throw(): void
    {
        Queue::fake();
        $this->fakeTransport(0, ['error' => 'cURL error 28: Operation timed out', 'timeout' => true]);

        $record = OmniTax::report($this->sale('B-2'));

        $this->assertSame(FiscalInvoice::PENDING, $record->status);
        $this->assertStringContainsString('timed out', $record->last_error);
        Queue::assertPushed(SubmitFiscalInvoice::class);
    }

    public function test_an_authority_server_error_is_queued_too(): void
    {
        Queue::fake();
        $this->fakeTransport(503, []);

        $record = OmniTax::report($this->sale('B-3'));

        $this->assertSame(FiscalInvoice::PENDING, $record->status);
        Queue::assertPushed(SubmitFiscalInvoice::class);
    }

    public function test_a_rejection_is_failed_and_not_queued(): void
    {
        Queue::fake();
        $this->fakeTransport(200, ['validationResponse' => [
            'statusCode' => '01', 'status' => 'Invalid', 'errorCode' => '0052', 'error' => 'Invalid HS Code',
        ]]);

        $record = OmniTax::report($this->sale('B-4'));

        $this->assertSame(FiscalInvoice::FAILED, $record->status);
        $this->assertStringContainsString('0052', $record->last_error);
        Queue::assertNothingPushed();
    }

    public function test_the_realtime_attempt_is_capped_at_the_configured_timeout(): void
    {
        Queue::fake();
        config()->set('omnitax.realtime.timeout', 2.5);
        $this->app->forgetInstance('omnitax');
        $fake = $this->fakeTransport(0, ['error' => 'down']);

        OmniTax::report($this->sale('B-5'));

        $this->assertSame(2.5, $fake->timeout);
    }

    public function test_with_realtime_off_nothing_is_attempted_inline(): void
    {
        Queue::fake();
        config()->set('omnitax.realtime.enabled', false);
        $this->app->forgetInstance('omnitax');
        $fake = $this->fakeTransport(200, []);

        $record = OmniTax::report($this->sale('B-6'));

        $this->assertSame(0, $fake->calls, 'no inline call');
        $this->assertSame(FiscalInvoice::PENDING, $record->status);
        Queue::assertPushed(SubmitFiscalInvoice::class);
    }

    public function test_reporting_an_accepted_sale_again_never_reports_it_twice(): void
    {
        Queue::fake();
        $first = OmniTax::report($this->sale('B-7'));
        $fake = $this->fakeTransport(200, []);

        $again = OmniTax::report($this->sale('B-7'));

        $this->assertSame($first->id, $again->id);
        $this->assertSame(FiscalInvoice::VALID, $again->status, 'not downgraded to pending');
        $this->assertSame(0, $fake->calls);
    }

    // ── #75: the idempotency key ───────────────────────────────────────────

    public function test_two_identical_sales_with_their_own_numbers_are_two_records(): void
    {
        Queue::fake();

        $a = OmniTax::report($this->sale('B-100'));
        $b = OmniTax::report($this->sale('B-101'));

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, FiscalInvoice::reported()->count());
    }

    // ── Transport ──────────────────────────────────────────────────────────

    public function test_a_connection_failure_is_returned_as_status_zero_not_thrown(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = (new HttpTransport(30))->post('https://example.test', []);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['body']['timeout']);
    }

    public function test_the_job_retries_an_unreachable_authority_and_skips_a_reported_sale(): void
    {
        Queue::fake();
        $this->fakeTransport(0, ['error' => 'down']);
        $record = OmniTax::report($this->sale('B-8'));

        $job = (new SubmitFiscalInvoice($record))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased();

        // Now the authority is back; the job lands it.
        $this->app->offsetUnset(Transport::class);
        $this->app->singleton(Transport::class, fn () => new \Nosh\OmniTax\Transport\MockTransport());
        (new SubmitFiscalInvoice($record))->handle();
        $this->assertSame(FiscalInvoice::VALID, $record->fresh()->status);

        // And a second job for the same sale does nothing.
        $fake = $this->fakeTransport(200, []);
        (new SubmitFiscalInvoice($record->fresh()))->handle();
        $this->assertSame(0, $fake->calls);
    }

    public function test_a_job_waits_while_another_is_sending_the_same_sale(): void
    {
        Queue::fake();
        $this->fakeTransport(0, ['error' => 'down']);
        $record = OmniTax::report($this->sale('B-9'));
        $fake = $this->fakeTransport(200, []);

        $lock = \Illuminate\Support\Facades\Cache::lock('omnitax-submit-'.$record->id, 120);
        $lock->get();

        $job = (new SubmitFiscalInvoice($record))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(30);
        $this->assertSame(0, $fake->calls, 'nothing sent while locked');
        $lock->release();
    }

    // ── QR for SRB / PRA ───────────────────────────────────────────────────

    public function test_a_saved_pra_record_prints_the_verification_url_not_the_number(): void
    {
        Queue::fake();
        $this->usePerAuthorityMocks();
        $this->praCredentials();

        $record = OmniTax::authority('pra')->report($this->sale('P-1'));

        $this->assertSame(FiscalInvoice::VALID, $record->status, (string) $record->last_error);
        $this->assertStringStartsWith('http', $record->qr()->payload());
        $this->assertStringContainsString($record->fiscal_number, $record->qr()->payload());
    }

    // ── Credit notes ───────────────────────────────────────────────────────

    public function test_supports_reports_what_each_authority_can_do(): void
    {
        $this->assertFalse(OmniTax::supports(Feature::CREDIT_NOTE), 'FBR has no credit note');
        $this->assertTrue(OmniTax::supports(Feature::REMOTE_VALIDATION));

        $this->usePerAuthorityMocks();
        $this->praCredentials();
        $this->assertTrue(OmniTax::authority('pra')->supports(Feature::CREDIT_NOTE));
    }

    public function test_a_credit_note_to_fbr_is_refused_clearly_and_not_sent(): void
    {
        $fake = $this->fakeTransport(200, []);

        $refund = (new InvoiceBuilder())
            ->date('2026-10-06')->walkInCustomer('Sindh')->number('R-1')
            ->creditNoteFor('B-1', '7000007DI1747119701593')
            ->addItem((new LineItemBuilder())->description('Zinger Burger')->quantity(1)->unitPrice(650)->taxRate('16%')->saleType('Services'))
            ->build();

        $response = OmniTax::submit($refund);

        $this->assertFalse($response->isValid());
        $this->assertFalse($response->isRetryable());
        $this->assertStringContainsString('credit notes', $response->errors()[0]);
        $this->assertSame(0, $fake->calls, 'never sent as an ordinary sale');
    }

    public function test_a_credit_note_to_pra_is_accepted_and_references_the_original(): void
    {
        $this->usePerAuthorityMocks();
        $this->praCredentials();

        $refund = (new InvoiceBuilder())
            ->date('2026-10-06')->walkInCustomer('Punjab')->number('R-2')
            ->creditNoteFor('B-2', '9000052011142444901')
            ->addItem((new LineItemBuilder())->description('Zinger Burger')->quantity(1)->unitPrice(650)->taxRate('16%')->saleType('Services'))
            ->build();

        $this->assertSame('B-2', $refund->meta['refUsin']);
        $this->assertTrue(OmniTax::authority('pra')->submit($refund)->isValid());
    }

    // ── check() ────────────────────────────────────────────────────────────

    public function test_check_passes_for_a_complete_fbr_setup_and_reaches_the_authority(): void
    {
        $check = OmniTax::check();

        $this->assertTrue($check->ok(), (string) $check->failure());
        $this->assertSame('fbr', $check->authority());
        $this->assertTrue($check->reachedAuthority());
    }

    public function test_check_explains_missing_credentials(): void
    {
        OmniTax::resolveCredentialsUsing(fn () => null);

        $check = OmniTax::check();

        $this->assertFalse($check->ok());
        $this->assertStringContainsString('No fiscal credentials', $check->failure());
    }

    public function test_check_for_pra_is_local_and_names_what_is_missing(): void
    {
        $this->usePerAuthorityMocks();
        OmniTax::resolveCredentialsUsing(fn () => new Credentials(
            authority: 'pra', token: 'test-token', sandbox: true, mode: 'cloud',
            seller: new Seller('1234567', 'Test Restaurant', 'Punjab', 'Lahore'),
        ));

        $check = OmniTax::check();

        $this->assertFalse($check->ok());
        $this->assertFalse($check->reachedAuthority());
        $this->assertStringContainsString('POS ID', $check->failure());
    }
}
