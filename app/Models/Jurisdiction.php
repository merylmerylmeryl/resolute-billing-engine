<?php

namespace App\Models;

use App\Enums\ExemptionType;
use Database\Factories\JurisdictionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'rate', 'exemption_type', 'exemption_amount'])]
#[WithoutTimestamps]
class Jurisdiction extends Model
{
    /** @use HasFactory<JurisdictionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'exemption_type' => ExemptionType::class,
            'exemption_amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsToMany<Property, $this>
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_jurisdictions');
    }
}
