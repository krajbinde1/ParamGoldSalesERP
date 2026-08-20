<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaharashtraDistrict extends Model
{
    protected $fillable = [
        'name',
        'former_name',
        'state',
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

    public function talukas(): HasMany
    {
        return $this->hasMany(MaharashtraTaluka::class, 'district_id')->orderBy('sort_order')->orderBy('name');
    }

    public function displayName(): string
    {
        if (filled($this->former_name)) {
            return $this->name.' ('.$this->former_name.')';
        }

        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'former_name' => $this->former_name,
            'label' => $this->displayName(),
            'state' => $this->state,
        ];
    }
}
