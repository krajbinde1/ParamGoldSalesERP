<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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

    private function throwIfFailed(Response $response, string $fallback): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? '');
        $code = $response->json('error.code');
        $details = (string) ($response->json('error.error_data.details') ?? '');

        $parts = array_values(array_filter([
            $message !== '' ? $message : $fallback,
            $details !== '' ? $details : null,
            is_numeric($code) ? 'Meta error '.$code : null,
            'HTTP '.$response->status(),
        ]));

        throw new RuntimeException(implode(' — ', $parts));
    }
}
