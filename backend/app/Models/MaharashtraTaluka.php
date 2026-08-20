<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaharashtraTaluka extends Model
{
    protected $fillable = [
        'district_id',
        'name',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(MaharashtraDistrict::class, 'district_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'district_id' => $this->district_id,
            'name' => $this->name,
        ];
    }
}
