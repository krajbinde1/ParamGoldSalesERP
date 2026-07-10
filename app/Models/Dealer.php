<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dealer extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Dealer $dealer): void {
            if (filled($dealer->dealer_code)) {
                return;
            }

            $lastCode = static::withTrashed()
                ->where('dealer_code', 'like', 'DLR%')
                ->orderByDesc('dealer_code')
                ->value('dealer_code');

            $nextNumber = $lastCode === null
                ? 1
                : ((int) substr($lastCode, 3)) + 1;

            $dealer->dealer_code = 'DLR'.str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
        });
    }

    protected $fillable = [
        'dealer_code',
        'firm_name',
        'owner_name',
        'mobile',
        'alternate_mobile',
        'whatsapp',
        'email',
        'gst_no',
        'fertilizer_license_no',
        'pan_no',
        'address',
        'village',
        'taluka',
        'district',
        'state',
        'pincode',
        'latitude',
        'longitude',
        'credit_limit',
        'outstanding',
        'dealer_type',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'credit_limit' => 'decimal:2',
        'outstanding' => 'decimal:2',
    ];
}
