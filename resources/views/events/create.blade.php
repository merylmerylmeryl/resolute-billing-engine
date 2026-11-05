@extends('layout')

@section('title', 'Record event')

@section('content')
    <h1>Record event</h1>

    <form method="POST" action="{{ route('events.store') }}" class="card event-form">
        @csrf

        <div class="form-row">
            <label for="property_id">Property</label>
            <select name="property_id" id="property_id" required>
                <option value="">Choose a property</option>
                @foreach ($properties as $property)
                    <option value="{{ $property->id }}" @selected((string) old('property_id') === (string) $property->id)>{{ $property->external_id }} (client {{ $property->client->external_id }})</option>
                @endforeach
            </select>
            @error('property_id') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-row">
            <label for="recorded_at">Date recorded</label>
            <input type="date" name="recorded_at" id="recorded_at" value="{{ old('recorded_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
            @error('recorded_at') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-row">
            <label for="status">Status</label>
            <select name="status" id="status" required>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(old('status', 'final') === $status->value)>{{ ucfirst($status->value) }}</option>
                @endforeach
            </select>
            @error('status') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-row">
            <label for="noticed_value">County value (noticed)</label>
            <input type="text" inputmode="decimal" name="noticed_value" id="noticed_value" value="{{ old('noticed_value') }}" required>
            <div class="form-hint">Dollars, at most 2 decimals.</div>
            @error('noticed_value') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-row">
            <label for="final_value">Final value</label>
            <input type="text" inputmode="decimal" name="final_value" id="final_value" value="{{ old('final_value') }}">
            <div class="form-hint">Required for a final event, leave empty for a pending one.</div>
            @error('final_value') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="form-submit">Record event</button>
    </form>
@endsection
