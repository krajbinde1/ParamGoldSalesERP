<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TallyOutboundVoucher;
use App\Services\TallySync\TallyConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TallyConnectorController extends Controller
{
    public function __construct(
        private readonly TallyConnectorService $connector,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? config('tally.connector.pending_limit_default', 10));
        $vouchers = $this->connector->pending($limit);

        return response()->json([
            'data' => array_map(fn (TallyOutboundVoucher $voucher): array => $this->format($voucher), $vouchers),
        ]);
    }

    public function claim(Request $request, TallyOutboundVoucher $tallyOutboundVoucher): JsonResponse
    {
        $validated = $request->validate([
            'connector_id' => ['nullable', 'string', 'max:100'],
        ]);

        $voucher = $this->connector->claim(
            $tallyOutboundVoucher,
            $this->connectorId($request, $validated['connector_id'] ?? null),
        );

        return response()->json([
            'message' => 'Voucher claimed.',
            'data' => $this->format($voucher),
        ]);
    }

    public function synced(Request $request, TallyOutboundVoucher $tallyOutboundVoucher): JsonResponse
    {
        $validated = $request->validate([
            'tally_voucher_no' => ['nullable', 'string', 'max:100'],
            'tally_master_id' => ['nullable', 'string', 'max:100'],
        ]);

        $voucher = $this->connector->markSynced(
            $tallyOutboundVoucher,
            $validated['tally_voucher_no'] ?? null,
            $validated['tally_master_id'] ?? null,
        );

        return response()->json([
            'message' => 'Voucher marked as synced.',
            'data' => $this->format($voucher),
        ]);
    }

    public function failed(Request $request, TallyOutboundVoucher $tallyOutboundVoucher): JsonResponse
    {
        $validated = $request->validate([
            'error' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $voucher = $this->connector->markFailed($tallyOutboundVoucher, $validated['error']);

        return response()->json([
            'message' => 'Voucher marked as failed.',
            'data' => $this->format($voucher),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(TallyOutboundVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'source_type' => $voucher->source_type,
            'source_id' => $voucher->source_id,
            'voucher_type' => $voucher->voucher_type,
            'erp_reference' => $voucher->erp_reference,
            'status' => $voucher->status,
            'payload' => $voucher->payload,
            'attempts' => $voucher->attempts,
            'last_error' => $voucher->last_error,
            'claimed_at' => $voucher->claimed_at?->toIso8601String(),
            'claimed_until' => $voucher->claimed_until?->toIso8601String(),
            'tally_voucher_no' => $voucher->tally_voucher_no,
            'tally_master_id' => $voucher->tally_master_id,
            'synced_at' => $voucher->synced_at?->toIso8601String(),
        ];
    }

    private function connectorId(Request $request, ?string $fromBody): ?string
    {
        $header = trim((string) $request->header('X-Tally-Connector-Id', ''));
        if ($header !== '') {
            return mb_substr($header, 0, 100);
        }

        $fromBody = trim((string) $fromBody);

        return $fromBody === '' ? null : $fromBody;
    }
}
