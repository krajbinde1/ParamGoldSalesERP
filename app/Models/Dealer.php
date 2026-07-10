<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dealer extends Model
{
    use SoftDeletes;

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