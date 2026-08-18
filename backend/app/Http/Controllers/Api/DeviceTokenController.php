<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'installation_id' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:64'],
        ]);

        $token = $validated['token'];
        $installationId = $validated['installation_id']
            ?? $validated['device_id']
            ?? $request->header('X-Device-Id');
        $installationId = filled($installationId) ? trim((string) $installationId) : null;

        DeviceToken::query()
            ->where('token', $token)
            ->where('user_id', '!=', $request->user()->id)
            ->delete();

        // Single active mobile device: keep only this installation's FCM rows.
        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->when(
                $installationId !== null,
                fn ($q) => $q->where(function ($inner) use ($installationId): void {
                    $inner->whereNull('installation_id')
                        ->orWhere('installation_id', '!=', $installationId);
                }),
            )
            ->where('token', '!=', $token)
            ->delete();

        $record = DeviceToken::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'token' => $token,
            ],
            [
                'platform' => $validated['platform'] ?? 'android',
                'device_name' => $validated['device_name'] ?? null,
                'installation_id' => $installationId,
                'last_used_at' => now(),
            ],
        );

        Log::error('PARAMGOLD_LIVE_FCM TOKEN_REGISTERED', [
            'user_id' => $request->user()->id,
            'token_id' => $record->id,
            'token_suffix' => substr($token, -12),
            'platform' => $record->platform,
            'installation_id' => $installationId,
        ]);

        return response()->json([
            'success' => true,
            'id' => $record->id,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['success' => true]);
    }
}
