<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TallyOutboundVoucher;
use App\Services\TallySync\TallyConnectorService;
use App\Services\TallySync\TallyLiveBalanceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TallyConnectorController extends Controller
{
    public function __construct(
        private readonly TallyConnectorService $connector,
    ) {}

    public function heartbeat(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Tally connector authenticated.',
            'connector_id' => $this->connectorId($request, null),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            $limit = (int) ($validated['limit'] ?? config('tally.connector.pending_limit_default', 10));
            $vouchers = $this->connector->pending($limit);

            return response()->json([
                'data' => array_map(fn (TallyOutboundVoucher $voucher): array => $this->format($voucher), $vouchers),
            ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->connectorFailure($exception, 'pending');
        }
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

    public function liveBalancesPoll(TallyLiveBalanceService $live): JsonResponse
    {
        try {
            return response()->json($live->connectorPoll());
        } catch (Throwable $exception) {
            return $this->connectorFailure($exception, 'live-balances poll');
        }
    }

    public function liveBalances(Request $request, TallyLiveBalanceService $live): JsonResponse
    {
        try {
            $validated = $request->validate([
                'connector_id' => ['nullable', 'string', 'max:100'],
                'tally_online' => ['required', 'boolean'],
                'balances' => ['nullable', 'array', 'max:5000'],
                'balances.*.tally_ledger_name' => ['required_with:balances', 'string', 'max:255'],
                'balances.*.closing_balance' => ['required_with:balances', 'numeric'],
                'balances.*.closing_balance_type' => ['nullable', 'string', 'in:debit,credit'],
                'balances.*.closing_balance_raw' => ['nullable', 'string', 'max:100'],
                'balances.*.closing_balance_numeric' => ['nullable', 'numeric'],
                'balances.*.tally_is_debit' => ['nullable', 'boolean'],
                'balances.*.deemed_positive' => ['nullable', 'boolean'],
                'balances.*.is_closing_debit' => ['nullable', 'boolean'],
            ]);

            $result = $live->ingest(
                $this->connectorId($request, $validated['connector_id'] ?? null),
                (bool) $validated['tally_online'],
                $validated['balances'] ?? [],
            );

            return response()->json([
                'message' => $result['tally_online']
                    ? 'Live Tally balances stored.'
                    : 'Tally offline heartbeat stored.',
                'data' => $result,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->connectorFailure($exception, 'live-balances');
        }
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

    private function connectorFailure(Throwable $exception, string $action): JsonResponse
    {
        report($exception);

        return response()->json([
            'success' => false,
            'message' => $this->connectorFailureMessage($exception, $action),
        ], 500);
    }

    private function connectorFailureMessage(Throwable $exception, string $action): string
    {
        $raw = $exception->getMessage();

        if ($exception instanceof QueryException) {
            $sql = method_exists($exception, 'getSql') ? (string) $exception->getSql() : '';
            $haystack = strtolower($raw.' '.$sql);
            if (str_contains($haystack, 'tally_outbound_vouchers')
                || str_contains($haystack, 'tally_live_sync_states')
                || str_contains($haystack, "doesn't exist")
                || str_contains($haystack, 'base table or view not found')) {
                return 'Tally connector database tables are missing. On the ERP server run: php artisan migrate';
            }
        }

        $detail = trim($raw);

        return $detail === ''
            ? "Tally connector {$action} failed."
            : "Tally connector {$action} failed: {$detail}";
    }
}
