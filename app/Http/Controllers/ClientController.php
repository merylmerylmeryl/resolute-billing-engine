<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Property;
use Illuminate\Contracts\View\View;

class ClientController extends Controller
{
    public function index(): View
    {
        $clients = Client::withCount(['properties', 'invoices'])
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
            'properties.invoiceLines',
        ]);

        $unbilled = $client->properties
            ->filter(fn (Property $property) => $property->invoiceLines->isEmpty())
            ->map(fn (Property $property) => [
                'property' => $property,
                'latest' => $property->events->last(),
            ]);

        return view('clients.show', ['client' => $client, 'unbilled' => $unbilled]);
    }
}
