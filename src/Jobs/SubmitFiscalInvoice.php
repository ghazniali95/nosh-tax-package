<?php

namespace Nosh\OmniTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nosh\OmniTax\Facades\OmniTax;
use Nosh\OmniTax\Models\FiscalInvoice;
use Nosh\OmniTax\Responses\FiscalResponse;

/**
 * Submits a persisted FiscalInvoice on the queue.
 *
 * Idempotent: the record's idempotency_key means a retry / double-dispatch
 * never reports the same sale twice — an already-valid record is skipped.
 * 5xx (server) failures retry with backoff; 4xx (client) failures do not.
 */
class SubmitFiscalInvoice implements ShouldQueue
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
    public function backoff(): array
    {
        return config('omnitax.retry_backoff', [10, 30, 120]);
    }

    public function handle(): void
    {
        // Idempotency guard — already reported, nothing to do.
        if ($this->record->status === FiscalInvoice::VALID) {
            return;
        }

        $manager = OmniTax::for($this->record->tenant_id ?: null);
        if ($this->record->authority) {
            $manager = $manager->authority($this->record->authority);
        }

        $response = $manager->submit($this->record->toInvoice());

        $this->record->recordResponse($response);

        // Only retry on transport/server errors — never on a business rejection.
        if (! $response->isValid() && $this->shouldRetry($response)) {
            $this->release($this->nextBackoff());
        }
    }

    protected function shouldRetry(FiscalResponse $response): bool
    {
        $status = $response->httpStatus();

        return $status >= 500 || $status === 0; // server error / network
    }

    protected function nextBackoff(): int
    {
        $backoff = $this->backoff();
        $attempt = max(0, $this->attempts() - 1);

        return $backoff[$attempt] ?? end($backoff) ?: 60;
    }
}
