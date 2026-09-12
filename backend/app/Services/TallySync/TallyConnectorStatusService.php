<?php

namespace App\Services\TallySync;

use App\Models\TallyLiveSyncState;
use Illuminate\Support\Carbon;

final class TallyConnectorStatusService
{
    /**
     * Shared connector status for Dashboard, Dealer Ledger, Total Outstanding,
     * and every other Tally page. Uses only the latest heartbeat / last_seen.
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
    public function snapshot(?Carbon $now = null): array
    {
        $state = TallyLiveSyncState::query()->orderBy('id')->first();
        if ($state instanceof TallyLiveSyncState) {
            return $state->dashboardStatus($now);
        }

        return (new TallyLiveSyncState(['tally_online' => false]))->dashboardStatus($now);
    }

    public function isConnected(?Carbon $now = null): bool
    {
        return $this->snapshot($now)['connected'];
    }

    public function lastHeartbeatLabel(?Carbon $now = null): string
    {
        return $this->snapshot($now)['last_heartbeat_label'];
    }

    public function lastTallySyncLabel(?Carbon $now = null): string
    {
        return $this->snapshot($now)['last_tally_sync_label'];
    }
}
