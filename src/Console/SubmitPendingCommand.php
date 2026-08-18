<?php

namespace Nosh\OmniTax\Console;

use Illuminate\Console\Command;
use Nosh\OmniTax\Jobs\SubmitFiscalInvoice;
use Nosh\OmniTax\Models\FiscalInvoice;

class SubmitPendingCommand extends Command
{
    protected $signature = 'fiscal:submit-pending {--authority=} {--sync : run inline instead of queueing}';

    protected $description = 'Dispatch any pending fiscal invoices for submission.';

    public function handle(): int
    {
        $query = FiscalInvoice::whereIn('status', [FiscalInvoice::PENDING, FiscalInvoice::FAILED]);
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
