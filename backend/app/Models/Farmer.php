<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Farmer extends Model
{
    public const MOBILE_REGEX = '/^[6-9][0-9]{9}$/';

    protected $fillable = [
        'name',
        'mobile',
        'district_id',
        'taluka_id',
        'village',
        'created_by_employee_id',
        'first_contact_date',
        'last_activity_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'first_contact_date' => 'date',
            'last_activity_date' => 'date',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(MaharashtraDistrict::class, 'district_id');
    }

    public function taluka(): BelongsTo
    {
        return $this->belongsTo(MaharashtraTaluka::class, 'taluka_id');
    }

    public function createdByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    public function fieldActivities(): HasMany
    {
        return $this->hasMany(FieldActivity::class);
    }

    public function latestActivity(): HasOne
    {
        return $this->hasOne(FieldActivity::class)->latestOfMany();
    }

    public function locationLabel(): string
    {
        return collect([
            $this->village,
            $this->taluka?->name,
            $this->district?->name,
        ])->filter()->implode(', ');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'district_id' => $this->district_id,
            'district_name' => $this->district?->name,
            'taluka_id' => $this->taluka_id,
            'taluka_name' => $this->taluka?->name,
            'village' => $this->village,
            'first_contact_date' => $this->first_contact_date?->toDateString(),
            'last_activity_date' => $this->last_activity_date?->toDateString(),
            'location' => $this->locationLabel(),
        ];
    }
}
