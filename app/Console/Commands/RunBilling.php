<?php

namespace App\Console\Commands;

use App\Billing\BillingRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[Signature('billing:run {as_of : Billing date, as Y-m-d}')]
#[Description('Bill every final outcome not yet billed, as of a date')]
class RunBilling extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BillingRunner $runner): int
    {
        $asOf = $this->argument('as_of');

        if (Validator::make(['as_of' => $asOf], ['as_of' => ['required', 'date_format:Y-m-d']])->fails()) {
            $this->error('The as-of date must be a real date in Y-m-d format, like 2026-07-01.');

            return self::FAILURE;
        }

        $issued = $runner->run($asOf);

        if ($issued === []) {
            $this->line('Nothing new to bill.');
        }

        foreach ($issued as $invoice) {
            $this->line(OutputFormatter::escape("{$invoice['client']} {$invoice['type']->value} {$invoice['total']}"));
        }

        return self::SUCCESS;
    }
}
