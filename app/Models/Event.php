<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Models\Concerns\Immutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['source_id', 'property_id', 'recorded_at', 'status', 'noticed_value', 'final_value'])]
#[WithoutTimestamps]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, Immutable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'date',
            'status' => EventStatus::class,
            'noticed_value' => 'decimal:2',
            'final_value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasOne<InvoiceLine, $this>
     */
    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class);
    }
}
