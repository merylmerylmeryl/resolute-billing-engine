<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A snapshot of one charge. The `savings` column is rounded for display, so never
 * calculate a fee from it: the exact savings and taxes live in `breakdown` as strings.
 */
#[Fillable([
    'invoice_id',
    'property_id',
    'event_id',
    'adjusts_line_id',
    'noticed_value',
    'final_value',
    'savings',
    'fee_rate',
    'fee',
    'amount',
    'breakdown',
])]
#[WithoutTimestamps]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory, Immutable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'noticed_value' => 'decimal:2',
            'final_value' => 'decimal:2',
            'savings' => 'decimal:2',
            'fee_rate' => 'decimal:4',
            'fee' => 'decimal:2',
            'amount' => 'decimal:2',
            'breakdown' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The property's original line for the season, which this adjustment corrects.
     *
     * @return BelongsTo<self, $this>
     */
    public function adjustedLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_line_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(self::class, 'adjusts_line_id');
    }
}
