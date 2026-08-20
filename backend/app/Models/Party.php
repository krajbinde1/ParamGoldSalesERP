<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Party extends Model
{
    protected $fillable = [
        'dealer_id',
        'party_name',
        'dealer_code',
        'owner_name',
        'mobile',
        'gst_no',
        'state',
        'district',
        'taluka',
        'village',
        'address',
    ];

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public static function firstOrCreateFromDealer(Dealer $dealer): self
    {
        return self::query()->firstOrCreate(
            ['dealer_id' => $dealer->id],
            [
                'party_name' => $dealer->firm_name,
                'dealer_code' => $dealer->dealer_code,
                'owner_name' => $dealer->owner_name,
                'mobile' => $dealer->mobile,
                'gst_no' => $dealer->gst_no,
                'state' => $dealer->state,
                'district' => $dealer->district,
                'taluka' => $dealer->taluka,
                'village' => $dealer->village,
                'address' => $dealer->address,
            ],
        );
    }
}
