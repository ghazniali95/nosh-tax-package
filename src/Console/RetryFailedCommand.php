<?php

namespace Nosh\OmniTax\Console;

use Illuminate\Console\Command;
use Nosh\OmniTax\Jobs\SubmitFiscalInvoice;
use Nosh\OmniTax\Models\FiscalInvoice;

class RetryFailedCommand extends Command
{
    protected $signature = 'fiscal:retry-failed {--sync}';

    protected $description = 'Retry fiscal invoices that previously failed submission.';

    public function handle(): int
    {
        $count = 0;
        FiscalInvoice::where('status', FiscalInvoice::FAILED)
            ->chunkById(100, function ($records) use (&$count) {
                foreach ($records as $record) {
                    $this->option('sync')
                        ? (new SubmitFiscalInvoice($record))->handle()
                        : SubmitFiscalInvoice::dispatch($record);
                    $count++;
                }
            });

        $this->info("Retried {$count} failed invoice(s).");

        return self::SUCCESS;
    }
}
