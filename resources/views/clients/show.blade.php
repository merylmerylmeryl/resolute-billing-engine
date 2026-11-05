@extends('layout')

@use('App\Billing\Money')

@section('title', 'Client '.$client->external_id)

@section('content')
    <h1>Client {{ $client->external_id }}</h1>

    @forelse ($client->invoices as $invoice)
        @php($isAdjustment = $invoice->type === \App\Enums\InvoiceType::Adjustment)
        <section class="invoice" id="invoice-{{ $invoice->id }}">
            <h2>{{ $isAdjustment ? 'Adjustment' : 'Invoice' }} #{{ $invoice->id }}</h2>
            <p>
                As of {{ $invoice->run->as_of->toDateString() }} (run #{{ $invoice->run_id }}).
                Total: <strong>{{ Money::format($invoice->total, $isAdjustment) }}</strong>
            </p>

            @foreach ($invoice->lines as $line)
                @php($note = $line->breakdown['note'] ?? null)
                <h3>{{ $line->property->external_id }}, season {{ $line->event->recorded_at->year }}</h3>
                <table>
                    <tbody>
                        <tr><th>Source event</th><td>{{ $line->event->source_id ?? 'form event #'.$line->event_id }} (recorded {{ $line->event->recorded_at->toDateString() }})</td></tr>
                        <tr><th>Noticed value</th><td class="num">{{ Money::format($line->noticed_value) }}</td></tr>
                        <tr><th>Final value</th><td class="num">{{ Money::format($line->final_value) }}</td></tr>
                        <tr><th>Savings</th><td class="num">{{ Money::format($line->savings) }}</td></tr>
                        <tr><th>Fee rate</th><td class="num">{{ Money::percent($line->fee_rate) }}</td></tr>
                        <tr><th>Calculated fee</th><td class="num">{{ Money::format($line->breakdown['calculated_fee']) }}</td></tr>
                        <tr>
                            <th>{{ $isAdjustment ? 'New total fee' : 'Fee' }}</th>
                            <td class="num">{{ Money::format($line->fee) }}@if ($note) <span class="muted">({{ str_replace('_', ' ', $note) }})</span>@endif</td>
                        </tr>
                        @if ($isAdjustment)
                            <tr>
                                <th>Change</th>
                                <td class="num">
                                    {{ Money::format(bcsub($line->fee, $line->amount, 2)) }} &rarr; {{ Money::format($line->fee) }}
                                    (<a href="#invoice-{{ $line->adjustedLine->invoice_id }}">original invoice #{{ $line->adjustedLine->invoice_id }}</a>)
                                </td>
                            </tr>
                        @endif
                        <tr><th>Amount</th><td class="num"><strong>{{ Money::format($line->amount, $isAdjustment) }}</strong></td></tr>
                    </tbody>
                </table>

                <table>
                    <thead>
                        <tr>
                            <th>Jurisdiction</th><th class="num">Rate per $100</th><th>Exemption</th>
                            <th class="num">Taxable (noticed)</th><th class="num">Tax (noticed)</th>
                            <th class="num">Taxable (final)</th><th class="num">Tax (final)</th><th class="num">Savings</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($line->breakdown['jurisdictions'] as $jurisdiction)
                            <tr>
                                <td>{{ $jurisdiction['code'] }}</td>
                                <td class="num">{{ $jurisdiction['rate'] }}</td>
                                <td>{{ $jurisdiction['exemption_type'] }}@if ($jurisdiction['exemption_type'] === 'flat') {{ Money::format($jurisdiction['exemption_amount']) }}@elseif ($jurisdiction['exemption_type'] === 'percent') {{ Money::percent($jurisdiction['exemption_amount']) }}@endif</td>
                                <td class="num">{{ Money::format($jurisdiction['taxable_at_noticed']) }}</td>
                                <td class="num">{{ Money::format($jurisdiction['tax_at_noticed']) }}</td>
                                <td class="num">{{ Money::format($jurisdiction['taxable_at_final']) }}</td>
                                <td class="num">{{ Money::format($jurisdiction['tax_at_final']) }}</td>
                                <td class="num">{{ Money::format($jurisdiction['savings']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </section>
    @empty
        <p>No invoices yet.</p>
    @endforelse

    @if ($unbilled->isNotEmpty())
        <h2>Not yet billed</h2>
        <table>
            <thead><tr><th>Property</th><th>Latest event</th></tr></thead>
            <tbody>
                @foreach ($unbilled as $row)
                    <tr>
                        <td>{{ $row['property']->external_id }}</td>
                        <td>{{ $row['latest'] ? $row['latest']->status->value.', recorded '.$row['latest']->recorded_at->toDateString() : 'no events' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
