@extends('layout')

@section('title', 'Clients')

@section('content')
    <h1>Clients</h1>

    <table>
        <thead>
            <tr><th>Client</th><th class="num">Properties</th><th class="num">Invoices</th></tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                <tr>
                    <td><a href="{{ route('clients.show', $client) }}">{{ $client->external_id }}</a></td>
                    <td class="num">{{ $client->properties_count }}</td>
                    <td class="num">{{ $client->invoices_count }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No clients yet. Run <code>php artisan billing:import</code>.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p>
        @if ($clients->previousPageUrl()) <a href="{{ $clients->previousPageUrl() }}">Previous</a> @endif
        Page {{ $clients->currentPage() }} of {{ $clients->lastPage() }}
        @if ($clients->nextPageUrl()) <a href="{{ $clients->nextPageUrl() }}">Next</a> @endif
    </p>
@endsection
