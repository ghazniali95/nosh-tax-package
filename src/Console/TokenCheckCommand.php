<?php

namespace Nosh\OmniTax\Console;

use Illuminate\Console\Command;

class TokenCheckCommand extends Command
{
    protected $signature = 'fiscal:token:check {--authority=} {--tenant=}';

    protected $description = 'Verify a fiscal token can reach the authority (does a lightweight reference call).';

    public function handle(): int
    {
        $manager = app('omnitax');
        if ($this->option('authority')) {
            $manager = $manager->authority($this->option('authority'));
        }
        if ($this->option('tenant')) {
            $manager = $manager->for($this->option('tenant'));
        }

        try {
            $credentials = $manager->credentials();
            if (! $credentials->hasToken()) {
                $this->error("No token configured for [{$credentials->authority}].");

                return self::FAILURE;
            }

            $provinces = $manager->provinces();
            $this->info("✓ Token for [{$credentials->authority}] reached the authority (".count($provinces).' provinces returned).');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Token check failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
