<?php

namespace App\Models;

use App\Services\TallySync\TallyConnectorStatusService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TallyLiveSyncState extends Model
{
    protected $fillable = [
        'connector_id',
        'tally_company',
        'tally_online',
        'last_seen_at',
        'last_heartbeat_at',
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
            'last_heartbeat_at' => 'datetime',
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
        return $this->connectorHeartbeatIsActive($now);
    }

    public function heartbeatAt(): ?Carbon
    {
        $heartbeat = $this->last_heartbeat_at;
        $seen = $this->last_seen_at;
        if ($heartbeat === null) {
            return $seen;
        }
        if ($seen === null) {
            return $heartbeat;
        }

        return $heartbeat->gte($seen) ? $heartbeat : $seen;
    }

    public function connectorHeartbeatIsActive(?Carbon $now = null): bool
    {
        $heartbeat = $this->heartbeatAt();
        if ($heartbeat === null) {
            return false;
        }

        $ttl = max(30, (int) config('tally.live_balance.offline_after_seconds', 120));
        $now ??= Carbon::now();

        return $heartbeat->gt($now->copy()->subSeconds($ttl));
    }

    public function recordHeartbeat(?string $connectorId = null, ?string $tallyCompany = null): self
    {
        $now = Carbon::now('Asia/Kolkata');
        $payload = [
            'last_heartbeat_at' => $now,
        ];
        $connectorId = trim((string) $connectorId);
        if ($connectorId !== '') {
            $payload['connector_id'] = mb_substr($connectorId, 0, 100);
        }
        $tallyCompany = trim((string) $tallyCompany);
        if ($tallyCompany !== '') {
            $payload['tally_company'] = mb_substr($tallyCompany, 0, 255);
        }

        $this->fill($payload);
        $this->save();

        return $this->fresh() ?? $this;
    }

    /**
     * @return array{
     *     connected: bool,
     *     label: string,
     *     last_sync_label: string,
     *     connector_name: string,
     *     tally_company: string,
     *     last_heartbeat_label: string,
     *     last_tally_sync_label: string,
     *     last_connected_label: string,
     *     offline_hint: string
     * }
     */
    public static function dashboardStatusSnapshot(?Carbon $now = null): array
    {
        return app(TallyConnectorStatusService::class)->snapshot($now);
    }

    /**
     * Dashboard connector status from heartbeat / last-seen only.
     * Does not use Tally online, ledger sync, or live balance match.
     *
     * @return array{
     *     connected: bool,
     *     label: string,
     *     last_sync_label: string,
     *     connector_name: string,
     *     tally_company: string,
     *     last_heartbeat_label: string,
     *     last_tally_sync_label: string,
     *     last_connected_label: string,
     *     offline_hint: string
     * }
     */
    public function dashboardStatus(?Carbon $now = null): array
    {
        $now ??= Carbon::now('Asia/Kolkata');
        $heartbeat = $this->heartbeatAt();
        $connected = $this->connectorHeartbeatIsActive($now);
        $clock = function (?Carbon $at): string {
            if ($at === null) {
                return '—';
            }

            return $at->timezone('Asia/Kolkata')->format('d M Y • h:i A');
        };

        return [
            'connected' => $connected,
            'label' => $connected ? 'Tally Connected' : 'Tally Disconnected',
            'last_sync_label' => $heartbeat !== null
                ? $heartbeat->timezone('Asia/Kolkata')->format('h:i A')
                : '—',
            'connector_name' => filled($this->connector_id) ? (string) $this->connector_id : '—',
            'tally_company' => filled($this->tally_company) ? (string) $this->tally_company : '—',
            'last_heartbeat_label' => $clock($heartbeat),
            'last_tally_sync_label' => $clock($this->last_balance_sync_at),
            'last_connected_label' => $clock($heartbeat),
            'offline_hint' => 'Start Tally Connector on the Tally PC',
        ];
    }

    public function tallyIsOnline(?Carbon $now = null): bool
    {
        return $this->connectorHeartbeatIsActive($now);
    }
}
