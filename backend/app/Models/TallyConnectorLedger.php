<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TallyConnectorLedger extends Model
{
    protected $fillable = [
        'tally_ledger_guid',
        'tally_ledger_name',
        'tally_ledger_name_normalized',
        'ledger_parent',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
