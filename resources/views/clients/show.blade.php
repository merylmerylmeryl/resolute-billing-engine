@extends('layout')

@use('App\Billing\AdjustmentSummary')
@use('App\Billing\FeeCalculator')
@use('App\Billing\Money')

@section('title', 'Client '.$client->external_id)

@section('content')
    <h1>Client {{ $client->external_id }}</h1>

    @forelse ($client->invoices as $invoice)
        @php($isAdjustment = $invoice->type === \App\Enums\InvoiceType::Adjustment)
        <section class="card invoice-card" id="invoice-{{ $invoice->id }}">
            <div class="invoice-head">
                <div>
                    <h2>{{ $isAdjustment ? 'Adjustment' : 'Invoice' }}</h2>
                    <dl class="invoice-details">
                        <dt>Number</dt><dd>#{{ $invoice->id }}</dd>
                        <dt>As of</dt><dd>{{ $invoice->run->as_of->toDateString() }}</dd>
                        <dt>Client</dt><dd>{{ $client->external_id }}</dd>
                    </dl>
                </div>
                <div>
                    <div class="amount-due-label">{{ Money::totalLabel($invoice->total, $isAdjustment) }}</div>
                    <div class="amount-due">{{ Money::absolute($invoice->total) }}</div>
                </div>
            </div>

            <table class="lines-table">
                <colgroup>
                    <col class="col-property"><col class="col-county-value"><col class="col-final-value"><col class="col-savings">
                    <col class="col-rate"><col class="col-fee"><col class="{{ $isAdjustment ? 'col-amount-due' : 'col-note' }}">
                </colgroup>
                @foreach ($invoice->lines as $line)
                    @php($note = $line->breakdown['note'] ?? null)
                    <tbody class="line-group">
                        <tr class="line-header">
                            <th class="col-property">Property</th>
                            <th class="col-county-value">County value</th>
                            <th class="col-final-value">Final value</th>
                            <th class="col-savings">Tax savings</th>
                            <th class="col-rate">Rate</th>
                            @if ($isAdjustment)
                                <th class="col-fee">Fee</th>
                                <th class="col-amount-due">Amount due</th>
                            @else
                                <th class="col-fee">Our fee</th>
                                <th class="col-note">Note</th>
                            @endif
                        </tr>
                        <tr class="line-row">
                            <td class="col-property">
                                <strong>{{ $line->property->external_id }}</strong>
                                <div class="property-meta">
                                    {{ $line->event->source_id ?? 'form #'.$line->event_id }}, recorded {{ $line->event->recorded_at->toDateString() }}<br>
                                    Tax year {{ $line->event->recorded_at->year }}
                                </div>
                            </td>
                            <td class="col-county-value">{{ Money::value($line->noticed_value) }}</td>
                            <td class="col-final-value">{{ Money::value($line->final_value) }}</td>
                            <td class="col-savings {{ bccomp($line->savings, '0', 2) > 0 ? 'savings' : '' }}">{{ Money::format($line->savings) }}</td>
                            <td class="col-rate">
                                {{ Money::percent($line->fee_rate) }}
                                @if (bccomp($line->fee_rate, FeeCalculator::VOLUME_RATE, 4) === 0)
                                    <div class="rate-note">{{ FeeCalculator::VOLUME_RATE_MINIMUM_PROPERTIES }}+ properties</div>
                                @endif
                            </td>
                            @if ($isAdjustment)
                                <td class="col-fee">{{ Money::format(bcsub($line->fee, $line->amount, 2)) }} &rarr; {{ Money::format($line->fee) }}</td>
                                <td class="col-amount-due">
                                    <strong>{{ Money::amountDue($line->amount) }}</strong>
                                    <div class="small"><a href="#invoice-{{ $line->adjustedLine->invoice_id }}">original invoice #{{ $line->adjustedLine->invoice_id }}</a></div>
                                </td>
                            @else
                                <td class="col-fee">
                                    <strong>{{ Money::format($line->fee) }}</strong>
                                    @if ($note === 'waived' || $note === 'capped')
                                        <div class="fee-calculated">{{ Money::format($line->breakdown['calculated_fee']) }}</div>
                                    @endif
                                </td>
                                <td class="col-note">
                                    @if ($note === 'waived') Waived, under {{ Money::value(FeeCalculator::WAIVER_THRESHOLD) }}
                                    @elseif ($note === 'capped') Capped at {{ Money::value(FeeCalculator::FEE_CAP) }}
                                    @elseif ($note === 'no_savings') No savings
                                    @endif
                                </td>
                            @endif
                        </tr>
                        @if ($isAdjustment && isset($previousLines[$line->id]))
                            <tr>
                                <td colspan="7" class="line-explain">{{ AdjustmentSummary::describe($previousLines[$line->id], $line) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td colspan="7" class="line-breakdown">
                                <table class="tax-table">
                                    <colgroup>
                                        <col class="tax-col-jurisdiction"><col class="tax-col-rate"><col class="tax-col-exemption">
                                        <col class="tax-col-amount"><col class="tax-col-amount"><col class="tax-col-amount"><col class="tax-col-amount"><col class="tax-col-amount">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th class="tax-col-jurisdiction">Jurisdiction</th>
                                            <th class="tax-col-rate">Rate / $100</th>
                                            <th class="tax-col-exemption">Exemption</th>
                                            <th class="tax-col-amount">County taxable</th>
                                            <th class="tax-col-amount">Tax (county)</th>
                                            <th class="tax-col-amount">Final taxable</th>
                                            <th class="tax-col-amount">Tax (final)</th>
                                            <th class="tax-col-amount">Tax savings</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($line->breakdown['jurisdictions'] as $jurisdiction)
                                            <tr>
                                                <td class="tax-col-jurisdiction">{{ $jurisdiction['code'] }}</td>
                                                <td class="tax-col-rate">{{ Money::rate($jurisdiction['rate']) }}</td>
                                                <td class="tax-col-exemption">{{ match ($jurisdiction['exemption_type']) {
                                                    'flat' => Money::value($jurisdiction['exemption_amount']).' homestead',
                                                    'percent' => Money::percent($jurisdiction['exemption_amount']).' homestead',
                                                    default => 'none',
                                                } }}</td>
                                                <td class="tax-col-amount">{{ Money::value($jurisdiction['taxable_at_noticed']) }}</td>
                                                <td class="tax-col-amount">{{ Money::format($jurisdiction['tax_at_noticed']) }}</td>
                                                <td class="tax-col-amount">{{ Money::value($jurisdiction['taxable_at_final']) }}</td>
                                                <td class="tax-col-amount">{{ Money::format($jurisdiction['tax_at_final']) }}</td>
                                                <td class="tax-col-amount">{{ Money::format($jurisdiction['savings']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
                <tfoot>
                    <tr class="line-total">
                        @if ($isAdjustment)
                            <td colspan="6">Total</td>
                            <td class="col-amount-due">{{ Money::amountDue($invoice->total) }}</td>
                        @else
                            <td colspan="5">Total</td>
                            <td class="col-fee">{{ Money::format($invoice->total) }}</td>
                            <td class="col-note"></td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </section>
    @empty
        <p>No invoices yet.</p>
    @endforelse

    @if ($unbilled->isNotEmpty())
        <section class="card">
            <h2>Not yet billed</h2>
            <table class="list-table">
                <thead><tr><th>Property</th><th>Latest event</th></tr></thead>
                <tbody>
                    @foreach ($unbilled as $row)
                        <tr>
                            <td>{{ $row['property']->external_id }}</td>
                            <td>{{ $row['latest'] ? ucfirst($row['latest']->status->value).', recorded '.$row['latest']->recorded_at->toDateString() : 'no events' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endsection
