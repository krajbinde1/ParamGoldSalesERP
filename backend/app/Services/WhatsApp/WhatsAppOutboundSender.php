<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppOutboundMessage;
use App\Support\IndianCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class WhatsAppOutboundSender
{
    public function __construct(
        private readonly WhatsAppCloudClient $cloud,
    ) {}

    public function send(WhatsAppOutboundMessage $message): WhatsAppOutboundMessage
    {
        $message->refresh();

        if (! $message->isPending()) {
            return $message;
        }

        if (! $this->cloud->isConfigured()) {
            return $message;
        }

        $message->increment('attempts');

        try {
            $payload = $message->payload ?? [];
            $to = WhatsAppPhoneNumber::toApi($message->to_number);
            if ($to === null) {
                throw new RuntimeException(WhatsAppOutboundEnqueueService::ERROR_INVALID_MOBILE);
            }

            $type = (string) ($payload['type'] ?? $message->source_type);
            $result = $type === 'collection' || $message->source_type === WhatsAppOutboundMessage::SOURCE_COLLECTION
                ? $this->sendCollection($to, $payload)
                : $this->sendBill($to, $payload);

            $message->update([
                'status' => WhatsAppOutboundMessage::STATUS_SENT,
                'error' => null,
                'meta_message_id' => $result['message_id'],
                'meta_media_id' => $result['media_id'],
                'sent_at' => Carbon::now('Asia/Kolkata'),
            ]);
        } catch (RuntimeException $exception) {
            $message->update([
                'status' => WhatsAppOutboundMessage::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
        }

        return $message->fresh() ?? $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message_id: string, media_id: ?string}
     */
    private function sendBill(string $to, array $payload): array
    {
        $path = (string) ($payload['bill_path'] ?? '');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            throw new RuntimeException(WhatsAppOutboundEnqueueService::ERROR_MISSING_BILL);
        }

        $absolute = Storage::disk('public')->path($path);
        $kind = (string) ($payload['media_kind'] ?? 'document');
        $mime = (string) ($payload['mime_type'] ?? 'application/octet-stream');
        $filename = (string) ($payload['filename'] ?? basename($path));
        $caption = (string) ($payload['body'] ?? $this->fallbackBillCaption($payload));

        $media = $this->cloud->uploadMedia($absolute, $mime, $filename);
        $template = $kind === 'image'
            ? trim((string) config('services.whatsapp.bill_image_template'))
            : trim((string) config('services.whatsapp.bill_template'));

        if ($template !== '') {
            $sent = $this->cloud->sendMessage([
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => (string) config('services.whatsapp.template_language', 'en')],
                    'components' => [
                        [
                            'type' => 'header',
                            'parameters' => [[
                                'type' => $kind === 'image' ? 'image' : 'document',
                                $kind === 'image' ? 'image' : 'document' => array_filter([
                                    'id' => $media['id'],
                                    'filename' => $kind === 'image' ? null : $filename,
                                ]),
                            ]],
                        ],
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => (string) ($payload['dealer_name'] ?? '')],
                                ['type' => 'text', 'text' => (string) ($payload['bill_number'] ?? '')],
                                ['type' => 'text', 'text' => (string) ($payload['bill_date'] ?? '')],
                                ['type' => 'text', 'text' => (string) ($payload['grand_total_label'] ?? IndianCurrency::format($payload['grand_total'] ?? 0))],
                            ],
                        ],
                    ],
                ],
            ]);
        } elseif ($kind === 'image') {
            $sent = $this->cloud->sendMessage([
                'to' => $to,
                'type' => 'image',
                'image' => [
                    'id' => $media['id'],
                    'caption' => $caption,
                ],
            ]);
        } else {
            $sent = $this->cloud->sendMessage([
                'to' => $to,
                'type' => 'document',
                'document' => [
                    'id' => $media['id'],
                    'caption' => $caption,
                    'filename' => $filename,
                ],
            ]);
        }

        return [
            'message_id' => $sent['id'],
            'media_id' => $media['id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message_id: string, media_id: ?string}
     */
    private function sendCollection(string $to, array $payload): array
    {
        $template = trim((string) config('services.whatsapp.collection_template'));
        $body = (string) ($payload['body'] ?? $this->fallbackCollectionCaption($payload));

        if ($template !== '') {
            $sent = $this->cloud->sendMessage([
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => (string) config('services.whatsapp.template_language', 'en')],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => (string) ($payload['dealer_name'] ?? '')],
                            ['type' => 'text', 'text' => (string) ($payload['amount_label'] ?? IndianCurrency::format($payload['amount'] ?? 0))],
                            ['type' => 'text', 'text' => (string) ($payload['receipt_no'] ?? '')],
                            ['type' => 'text', 'text' => (string) ($payload['collection_date'] ?? '')],
                        ],
                    ]],
                ],
            ]);
        } else {
            $sent = $this->cloud->sendMessage([
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $body,
                ],
            ]);
        }

        return [
            'message_id' => $sent['id'],
            'media_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fallbackBillCaption(array $payload): string
    {
        return WhatsAppBillCopy::body(
            (string) ($payload['dealer_name'] ?? 'Dealer'),
            (string) ($payload['order_no'] ?? ''),
            (float) ($payload['grand_total'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fallbackCollectionCaption(array $payload): string
    {
        return 'Dear '.(string) ($payload['dealer_name'] ?? 'Dealer')
            .', we have received payment of '.(string) ($payload['amount_label'] ?? '')
            .'. Receipt '.(string) ($payload['receipt_no'] ?? '')
            .' dated '.(string) ($payload['collection_date'] ?? '').'.';
    }
}
