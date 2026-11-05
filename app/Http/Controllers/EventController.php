<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Http\Requests\StoreEventRequest;
use App\Models\Event;
use App\Models\Property;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class EventController extends Controller
{
    public function create(): View
    {
        return view('events.create', [
            'properties' => Property::with('client')->orderBy('external_id')->get(),
            'statuses' => EventStatus::cases(),
        ]);
    }

    /**
     * Form events always have a null source_id, so they are never de-duplicated
     * the way imported events are. The values arrive as validated strings and are
     * stored as given, so no float ever touches them.
     */
    public function store(StoreEventRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $event = Event::create([
            'source_id' => null,
            'property_id' => $validated['property_id'],
            'recorded_at' => $validated['recorded_at'],
            'status' => $validated['status'],
            'noticed_value' => $validated['noticed_value'],
            'final_value' => $validated['status'] === EventStatus::Final->value ? $validated['final_value'] : null,
        ]);

        return redirect()
            ->route('clients.show', $event->property->client)
            ->with('status', 'Event recorded for '.$event->property->external_id.'. It will be billed the next time billing runs.');
    }
}
