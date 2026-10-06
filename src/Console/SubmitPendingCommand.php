<?php

namespace Nosh\OmniTax\Console;

use Illuminate\Console\Command;
use Nosh\OmniTax\Jobs\SubmitFiscalInvoice;
use Nosh\OmniTax\Models\FiscalInvoice;

class SubmitPendingCommand extends Command
{
    protected $signature = 'fiscal:submit-pending {--authority=} {--older-than=5 : minutes a record must have waited, so a sale whose job is still queued is not sent twice} {--sync : run inline instead of queueing}';

    protected $description = 'Dispatch any pending fiscal invoices for submission.';

    public function handle(): int
    {
        // PENDING only. A FAILED record was rejected by the authority and will be
        // rejected again unchanged — that is fiscal:retry-failed, after a fix.
        $query = FiscalInvoice::pending()
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('older-than')));
        if ($this->option('authority')) {
            $query->where('authority', $this->option('authority'));
        }

        $count = 0;
        $query->chunkById(100, function ($records) use (&$count) {
            foreach ($records as $record) {
                $this->option('sync')
                    ? (new SubmitFiscalInvoice($record))->handle()
                    : SubmitFiscalInvoice::dispatch($record);
                $count++;
            }
        });

        $this->info("Processed {$count} pending invoice(s).");

        return self::SUCCESS;
    }
}
