@extends('layout')

@section('title', 'Clients')

@section('content')
    <h1>Clients</h1>

    <div class="card">
    <table class="list-table">
        <thead>
            <tr><th>Client</th><th class="num">Properties</th><th class="num">Invoices</th><th class="num">Adjustments</th></tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                <tr>
                    <td><a href="{{ route('clients.show', $client) }}">{{ $client->external_id }}</a></td>
                    <td class="num">{{ $client->properties_count }}</td>
                    <td class="num">{{ $client->invoices_count }}</td>
                    <td class="num">{{ $client->adjustments_count }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No clients yet. Run <code>php artisan billing:import</code>.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <p>
        @if ($clients->previousPageUrl()) <a href="{{ $clients->previousPageUrl() }}">Previous</a> @endif
        Page {{ $clients->currentPage() }} of {{ $clients->lastPage() }}
        @if ($clients->nextPageUrl()) <a href="{{ $clients->nextPageUrl() }}">Next</a> @endif
    </p>
@endsection
