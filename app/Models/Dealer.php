<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dealer extends Model
{
    protected $fillable = [
        'dealer_code',
        'firm_name',
        'owner_name',
        'mobile',
        'alternate_mobile',
        'email',
        'gst_no',
        'address',
        'state',
        'district',
        'taluka',
        'village',
        'pincode',
        'credit_limit',
        'outstanding',
        'latitude',
        'longitude',
        'status',
    ];
}