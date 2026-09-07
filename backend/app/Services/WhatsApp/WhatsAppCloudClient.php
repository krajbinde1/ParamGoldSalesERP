<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class WhatsAppCloudClient
{
    public function isConfigured(): bool
    {
        if (! (bool) config('services.whatsapp.enabled', true)) {
            return false;
        }

        return filled(config('services.whatsapp.token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * @return array{id: string}
     */
    public function uploadMedia(string $absolutePath, string $mimeType, string $filename): array
    {
        $this->assertConfigured();

        if (! is_file($absolutePath)) {
            throw new RuntimeException('WhatsApp media file is missing on disk.');
        }

        $response = Http::withToken((string) config('services.whatsapp.token'))
            ->timeout(60)
            ->attach('file', (string) file_get_contents($absolutePath), $filename)
            ->post($this->url('media'), [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        $this->logResult('media', [
            'filename' => $filename,
            'mime_type' => $mimeType,
        ], $response);

        $this->throwIfFailed($response, 'WhatsApp media upload failed.');

        $id = (string) ($response->json('id') ?? '');
        if ($id === '') {
            throw new RuntimeException('WhatsApp media upload returned no media id.');
        }

        return ['id' => $id];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{id: string}
     */
    public function sendMessage(array $message): array
    {
        $this->assertConfigured();

        $response = Http::withToken((string) config('services.whatsapp.token'))
            ->timeout(30)
            ->acceptJson()
            ->asJson()
            ->post($this->url('messages'), array_merge(
                ['messaging_product' => 'whatsapp'],
                $message,
            ));

        $this->logResult('messages', [
            'to' => $message['to'] ?? null,
            'message_type' => $message['type'] ?? null,
            'template' => $message['template']['name'] ?? null,
        ], $response);

        $this->throwIfFailed($response, 'WhatsApp message send failed.');

        $id = (string) ($response->json('messages.0.id') ?? '');
        if ($id === '') {
            throw new RuntimeException('WhatsApp send returned no message id.');
        }

        return ['id' => $id];
    }

    private function url(string $path): string
    {
        $version = trim((string) config('services.whatsapp.graph_version', 'v21.0'), '/');
        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');

        return 'https://graph.facebook.com/'.$version.'/'.$phoneNumberId.'/'.$path;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WhatsApp Cloud API credentials are not configured.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logResult(string $endpoint, array $context, Response $response): void
    {
        $error = $response->json('error');
        $ok = $response->successful();

        Log::log($ok ? 'info' : 'warning', 'WhatsApp API request result', array_filter([
            'endpoint' => $endpoint,
            'http_status' => $response->status(),
            'status' => $ok ? 'ok' : 'failed',
            'to' => $context['to'] ?? null,
            'message_type' => $context['message_type'] ?? null,
            'template' => $context['template'] ?? null,
            'filename' => $context['filename'] ?? null,
            'mime_type' => $context['mime_type'] ?? null,
            'meta_message_id' => $response->json('messages.0.id'),
            'meta_media_id' => $response->json('id'),
            'meta_error' => is_array($error) ? $error : null,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    private function throwIfFailed(Response $response, string $fallback): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? '');
        $code = $response->json('error.code');
        $details = (string) ($response->json('error.error_data.details') ?? '');
        $errorJson = $response->json('error');

        $parts = array_values(array_filter([
            $message !== '' ? $message : $fallback,
            $details !== '' ? $details : null,
            is_numeric($code) ? 'Meta error '.$code : null,
            'HTTP '.$response->status(),
            is_array($errorJson) ? json_encode($errorJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]));

        throw new RuntimeException(implode(' — ', $parts));
    }
}
