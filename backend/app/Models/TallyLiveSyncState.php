<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TallyLiveSyncState extends Model
{
    protected $fillable = [
        'connector_id',
        'tally_online',
        'last_seen_at',
        'last_tally_online_at',
        'last_balance_sync_at',
        'sync_requested_at',
        'last_matched_count',
    ];

    protected function casts(): array
    {
        return [
            'tally_online' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_tally_online_at' => 'datetime',
            'last_balance_sync_at' => 'datetime',
            'sync_requested_at' => 'datetime',
            'last_matched_count' => 'integer',
        ];
    }

    public static function current(): self
    {
        $existing = static::query()->orderBy('id')->first();
        if ($existing instanceof self) {
            return $existing;
        }

        return static::query()->create([
            'tally_online' => false,
            'last_matched_count' => 0,
        ]);
    }

    public function connectorIsFresh(?Carbon $now = null): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        $ttl = max(30, (int) config('tally.live_balance.offline_after_seconds', 120));
        $now ??= Carbon::now();

        return $this->last_seen_at->gt($now->copy()->subSeconds($ttl));
    }

    public function tallyIsOnline(?Carbon $now = null): bool
    {
        return $this->tally_online && $this->connectorIsFresh($now);
    }
}
