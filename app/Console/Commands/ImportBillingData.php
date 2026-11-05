<?php

namespace App\Console\Commands;

use App\Billing\DataImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[Signature('billing:import {path : Path to the JSON data file}')]
#[Description('Import properties and outcome events from a JSON file')]
class ImportBillingData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DataImporter $importer): int
    {
        try {
            $summary = $importer->import($this->argument('path'));
        } catch (InvalidArgumentException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        }

        foreach ($summary['warnings'] as $warning) {
            $this->warn(OutputFormatter::escape($warning));
        }

        foreach ($summary['rejected'] as $rejection) {
            $this->error(OutputFormatter::escape($rejection));
        }

        $this->line("Clients created: {$summary['clients_created']}");
        $this->line("Properties created: {$summary['properties_created']}");
        $this->line("Properties updated: {$summary['properties_updated']}");
        $this->line("Events created: {$summary['events_created']}");
        $this->line("Duplicates skipped: {$summary['duplicates_skipped']}");
        $this->line('Rows rejected: '.count($summary['rejected']));

        return $summary['rejected'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
