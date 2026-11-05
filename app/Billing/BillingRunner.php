<?php

namespace App\Billing;

use App\Enums\EventStatus;
use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Jurisdiction;
use App\Models\Property;
use App\Models\Run;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Bills every property and season whose latest outcome, as of a date, isn't billed yet.
 *
 * The whole run is one transaction, so a failure part way leaves nothing issued. Issued
 * records are immutable, so each invoice is created with its total already worked out.
 */
class BillingRunner
{
    public function __construct(private FeeCalculator $calculator) {}

    /**
     * @return list<array{client: string, type: InvoiceType, total: string}> What was issued, empty when there was nothing new.
     */
    public function run(string $asOf): array
    {
        return DB::transaction(function () use ($asOf) {
            $run = null;
            $issued = [];

            foreach ($this->clientsWithBillingData($asOf) as $client) {
                [$regularLines, $adjustmentLines] = collect($this->linesFor($client))
                    ->partition(fn (array $line) => $line['adjusts_line_id'] === null);

                foreach ([[InvoiceType::Invoice, $regularLines], [InvoiceType::Adjustment, $adjustmentLines]] as [$type, $lines]) {
                    if ($lines->isNotEmpty()) {
                        $run ??= Run::create(['as_of' => $asOf]);
                        $issued[] = $this->issue($run, $client, $type, $lines);
                    }
                }
            }

            return $issued;
        });
    }

    /**
     * Events are limited to the as-of date, using whereDate because a plain where drops
     * events recorded on that date. Lines aren't: a run with an older date must still
     * see everything already billed, so it never bills or un-bills it again.
     *
     * @return LazyCollection<int, Client>
     */
    private function clientsWithBillingData(string $asOf): LazyCollection
    {
        return Client::with([
            'properties' => fn ($query) => $query->orderBy('id'),
            'properties.jurisdictions',
            'properties.events' => fn ($query) => $query
                ->whereDate('recorded_at', '<=', $asOf)
                ->orderBy('recorded_at')
                ->orderBy('id'),
            'properties.invoiceLines' => fn ($query) => $query->orderBy('id'),
            'properties.invoiceLines.event',
        ])->lazyById();
    }

    /**
     * @return list<array<string, mixed>> The new lines for this client, across all of its properties and seasons.
     */
    private function linesFor(Client $client): array
    {
        $enrolledCounts = $client->properties
            ->flatMap(fn (Property $property) => $property->events->map(fn (Event $event) => $event->recorded_at->year)->unique())
            ->countBy();

        $lines = [];

        foreach ($client->properties as $property) {
            $linesBySeason = $property->invoiceLines->groupBy(fn (InvoiceLine $line) => $line->event->recorded_at->year);
            $eventsBySeason = $property->events->groupBy(fn (Event $event) => $event->recorded_at->year);

            foreach ($eventsBySeason as $season => $events) {
                $line = $this->lineFor($property, $events->last(), $linesBySeason->get($season, collect()), $enrolledCounts[$season]);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * Decides what one property owes for one season. An adjustment reuses the rate of the
     * line it corrects, so its difference reflects only the outcome. It is measured against
     * the latest line's fee, but always points at the original line.
     *
     * A revision that leaves the fee unchanged gets no line, so later runs recalculate it.
     *
     * @param  Collection<int, InvoiceLine>  $seasonLines  This property's lines for the season, oldest first.
     * @return array<string, mixed>|null
     */
    private function lineFor(Property $property, Event $latest, Collection $seasonLines, int $enrolledCount): ?array
    {
        if ($latest->status === EventStatus::Pending) {
            return null;
        }

        $previous = $seasonLines->last();

        if ($previous === null) {
            $result = $this->calculate($property, $latest, $this->calculator->rateFor($enrolledCount));

            return $this->lineAttributes($property, $latest, $result, $result->fee, null);
        }

        if (! $this->isNewer($latest, $previous->event)) {
            return null;
        }

        $result = $this->calculate($property, $latest, $previous->fee_rate);
        $difference = bcsub($result->fee, $previous->fee, 2);

        if (bccomp($difference, '0', 2) === 0) {
            return null;
        }

        return $this->lineAttributes($property, $latest, $result, $difference, $seasonLines->first()->id);
    }

    private function calculate(Property $property, Event $event, string $feeRate): FeeResult
    {
        $jurisdictions = $property->jurisdictions
            ->map(fn (Jurisdiction $jurisdiction) => [
                'code' => $jurisdiction->code,
                'rate' => $jurisdiction->rate,
                'exemption_type' => $jurisdiction->exemption_type,
                'exemption_amount' => $jurisdiction->exemption_amount,
            ])
            ->all();

        return $this->calculator->calculate($event->noticed_value, $event->final_value, $property->homestead, $jurisdictions, $feeRate);
    }

    /**
     * Compares by (recorded_at, id), because recorded_at has no time of day.
     */
    private function isNewer(Event $event, Event $other): bool
    {
        return [$event->recorded_at->toDateString(), $event->id] > [$other->recorded_at->toDateString(), $other->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineAttributes(Property $property, Event $event, FeeResult $result, string $amount, ?int $adjustsLineId): array
    {
        return [
            'property_id' => $property->id,
            'event_id' => $event->id,
            'adjusts_line_id' => $adjustsLineId,
            'noticed_value' => $event->noticed_value,
            'final_value' => $event->final_value,
            'savings' => $result->savings,
            'fee_rate' => $result->feeRate,
            'fee' => $result->fee,
            'amount' => $amount,
            'breakdown' => $result->toBreakdown(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{client: string, type: InvoiceType, total: string}
     */
    private function issue(Run $run, Client $client, InvoiceType $type, Collection $lines): array
    {
        $total = $lines->reduce(fn (string $sum, array $line) => bcadd($sum, $line['amount'], 2), '0.00');

        Invoice::create([
            'run_id' => $run->id,
            'client_id' => $client->id,
            'type' => $type,
            'total' => $total,
        ])->lines()->createMany($lines->all());

        return ['client' => $client->external_id, 'type' => $type, 'total' => $total];
    }
}
