<?php

namespace App\Http\Controllers\Api;

use App\Models\WhatsAppOutboundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController
{
    public function verify(Request $request): Response
    {
        $token = trim((string) config('services.whatsapp.webhook_verify_token'));
        $mode = $this->hubParam($request, 'mode');
        $challenge = $this->hubParam($request, 'challenge');
        $verifyToken = $this->hubParam($request, 'verify_token');

        if ($token === '' || $mode !== 'subscribe' || $verifyToken === '' || ! hash_equals($token, $verifyToken)) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): Response
    {
        try {
            $payload = $request->all();
            $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

            foreach ($entries as $entry) {
                $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
                foreach ($changes as $change) {
                    $statuses = $change['value']['statuses'] ?? [];
                    if (! is_array($statuses)) {
                        continue;
                    }
                    foreach ($statuses as $status) {
                        $this->applyStatus($status);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp webhook processing failed: '.$e->getMessage());
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(array $status): void
    {
        $providerId = (string) ($status['id'] ?? '');
        $remote = strtolower((string) ($status['status'] ?? ''));
        if ($providerId === '' || $remote === '') {
            return;
        }

        $message = WhatsAppOutboundMessage::query()
            ->where('meta_message_id', $providerId)
            ->first();

        if ($message === null) {
            return;
        }

        if ($message->isDelivered()) {
            return;
        }

        if ($remote === 'failed') {
            $error = (string) ($status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'WhatsApp delivery failed.');
            $message->update([
                'status' => WhatsAppOutboundMessage::STATUS_FAILED,
                'error' => mb_substr($error, 0, 2000),
            ]);

            return;
        }

        if (in_array($remote, ['delivered', 'read'], true)) {
            $message->update([
                'status' => WhatsAppOutboundMessage::STATUS_DELIVERED,
                'error' => null,
                'delivered_at' => Carbon::now('Asia/Kolkata'),
            ]);

            return;
        }

        if ($remote === 'sent' && $message->isPending()) {
            $message->update([
                'status' => WhatsAppOutboundMessage::STATUS_SENT,
                'error' => null,
                'sent_at' => $message->sent_at ?? Carbon::now('Asia/Kolkata'),
            ]);
        }
    }

    private function hubParam(Request $request, string $name): string
    {
        foreach (['hub.'.$name, 'hub_'.$name] as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
