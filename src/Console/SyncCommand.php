<?php

namespace Nosh\OmniTax\Console;

use Illuminate\Console\Command;
use Nosh\OmniTax\Facades\OmniTax;
use Nosh\OmniTax\Models\FiscalReferenceData;

class SyncCommand extends Command
{
    protected $signature = 'fiscal:sync {--type= : provinces|units|hsCodes|taxRates} {--authority=} {--force}';

    protected $description = 'Refresh cached authority reference data (provinces, units, HS codes, tax rates).';

    public function handle(): int
    {
        $authority = $this->option('authority') ?: config('omnitax.default');
        $types = $this->option('type') ? [$this->option('type')] : ['provinces', 'units', 'hsCodes', 'taxRates'];

        $manager = OmniTax::authority($authority);

        foreach ($types as $type) {
            try {
                $data = $manager->{$type}();
                FiscalReferenceData::updateOrCreate(
                    ['authority' => $authority, 'type' => $type],
                    ['data' => $data, 'synced_at' => now()],
                );
                $this->info("✓ {$authority} / {$type}: ".count($data).' rows cached.');
            } catch (\Throwable $e) {
                $this->warn("• {$authority} / {$type}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
