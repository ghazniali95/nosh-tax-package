<?php

namespace Nosh\OmniTax\Console;

use Illuminate\Console\Command;
use Nosh\OmniTax\Models\FiscalInvoice;

class StatusCommand extends Command
{
    protected $signature = 'fiscal:status {invoice : FiscalInvoice id or idempotency key}';

    protected $description = 'Inspect one fiscal invoice.';

    public function handle(): int
    {
        $key = $this->argument('invoice');
        $record = FiscalInvoice::where('id', $key)
            ->orWhere('idempotency_key', $key)
            ->orWhere('fiscal_number', $key)
            ->first();

        if (! $record) {
            $this->error("No fiscal invoice found for [{$key}].");

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['id', $record->id],
            ['tenant', $record->tenant_id],
            ['authority', $record->authority],
            ['status', $record->status],
            ['fiscal_number', $record->fiscal_number ?? '—'],
            ['submitted_at', (string) $record->submitted_at],
        ]);

        if ($errors = $record->response['errors'] ?? []) {
            $this->warn('Errors: '.implode('; ', $errors));
        }

        return self::SUCCESS;
    }
}
