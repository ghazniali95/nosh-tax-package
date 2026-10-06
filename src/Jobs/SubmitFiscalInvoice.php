<?php

namespace Nosh\OmniTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Nosh\OmniTax\Facades\OmniTax;
use Nosh\OmniTax\Models\FiscalInvoice;

/**
 * Submits a persisted FiscalInvoice on the queue.
 *
 * Idempotent: the record's idempotency_key means a retry / double-dispatch
 * never reports the same sale twice — an already-valid record is skipped.
 * 5xx (server) failures retry with backoff; 4xx (client) failures do not.
 */
class SubmitFiscalInvoice implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public function __construct(
        public FiscalInvoice $record,
    ) {
        $this->tries = (int) (config('omnitax.retry_attempts', 3));
        $this->onQueue(config('omnitax.queue', 'fiscal-invoices'));
    }

    /** @return int[] seconds to wait between attempts */
    /** One queued job per record: a second dispatch while one waits is dropped. */
    public function uniqueId(): string
    {
        return 'omnitax-invoice-'.$this->record->getKey();
    }

    public int $uniqueFor = 600;

    public function backoff(): array
    {
        return config('omnitax.retry_backoff', [10, 30, 120]);
    }

    public function handle(): void
    {
        // Two workers must never send the same sale at once — the queue's
        // uniqueness covers a second DISPATCH, this covers a second RUN (a
        // retried job overlapping a manual --sync, say). Whoever loses waits.
        $lock = Cache::lock('omnitax-submit-'.$this->record->getKey(), 120);

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $this->submit();
        } finally {
            $lock->release();
        }
    }

    protected function submit(): void
    {
        $this->record->refresh();

        // Idempotency guard — already reported (by the real-time attempt, or a
        // duplicate job), nothing to do.
        if ($this->record->status === FiscalInvoice::VALID) {
            return;
        }

        $manager = OmniTax::for($this->record->tenant_id ?: null);
        if ($this->record->authority) {
            $manager = $manager->authority($this->record->authority);
        }

        $response = $manager->submit($this->record->toInvoice());
        $this->record->recordResponse($response);

        // Only retry when the authority was never reached or failed on its side
        // — a business rejection will be refused again unchanged.
        if ($response->isRetryable()) {
            $this->release($this->nextBackoff());
        }
    }

    protected function nextBackoff(): int
    {
        $backoff = $this->backoff();
        $attempt = max(0, $this->attempts() - 1);

        return $backoff[$attempt] ?? end($backoff) ?: 60;
    }
}
