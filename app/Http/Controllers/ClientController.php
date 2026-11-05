<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\InvoiceLine;
use App\Models\Property;
use Illuminate\Contracts\View\View;

class ClientController extends Controller
{
    public function index(): View
    {
        $clients = Client::withCount([
            'properties',
            'invoices as invoices_count' => fn ($query) => $query->where('type', InvoiceType::Invoice->value),
            'invoices as adjustments_count' => fn ($query) => $query->where('type', InvoiceType::Adjustment->value),
        ])
            ->orderBy('external_id')
            ->paginate(25);

        return view('clients.index', ['clients' => $clients]);
    }

    /**
     * Invoice totals are stored on the invoice, so nothing is summed here.
     */
    public function show(Client $client): View
    {
        $client->load([
            'invoices' => fn ($query) => $query->orderBy('id'),
            'invoices.run',
            'invoices.lines' => fn ($query) => $query->orderBy('id'),
            'invoices.lines.property',
            'invoices.lines.event',
            'invoices.lines.adjustedLine.invoice',
            'properties' => fn ($query) => $query->orderBy('id'),
            'properties.events' => fn ($query) => $query->orderBy('recorded_at')->orderBy('id'),
            'properties.invoiceLines.event',
        ]);

        $unbilled = $client->properties
            ->filter(fn (Property $property) => $property->invoiceLines->isEmpty())
            ->map(fn (Property $property) => [
                'property' => $property,
                'latest' => $property->events->last(),
            ]);

        return view('clients.show', ['client' => $client, 'unbilled' => $unbilled, 'previousLines' => $this->previousLines($client)]);
    }

    /**
     * For each line, the property's line before it in the same season, which an
     * adjustment is measured against. Keyed by line id.
     *
     * @return array<int, InvoiceLine>
     */
    private function previousLines(Client $client): array
    {
        $previousLines = [];

        foreach ($client->properties as $property) {
            $property->invoiceLines
                ->sortBy('id')
                ->groupBy(fn (InvoiceLine $line) => $line->event->recorded_at->year)
                ->each(function ($seasonLines) use (&$previousLines) {
                    $previous = null;

                    foreach ($seasonLines as $line) {
                        if ($previous !== null) {
                            $previousLines[$line->id] = $previous;
                        }

                        $previous = $line;
                    }
                });
        }

        return $previousLines;
    }
}
