<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppOutboundMessage;
use App\Support\IndianCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
        $payload = $message->payload ?? [];

        try {
            $to = WhatsAppPhoneNumber::toApi($message->to_number);
            if ($to === null) {
                throw new RuntimeException(WhatsAppOutboundEnqueueService::ERROR_INVALID_MOBILE);
            }

            $type = (string) ($payload['type'] ?? $message->source_type);
            $result = match (true) {
                $type === 'payment_followup' || $message->source_type === WhatsAppOutboundMessage::SOURCE_PAYMENT_FOLLOWUP
                    => $this->sendPaymentReminder($to, $payload),
                $type === 'collection' || $message->source_type === WhatsAppOutboundMessage::SOURCE_COLLECTION
                    => $this->sendCollection($to, $payload),
                default => $this->sendBill($to, $payload),
            };

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

        $fresh = $message->fresh() ?? $message;
        $this->logOutbound($fresh);

        return $fresh;
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
                            'parameters' => $this->textParams([
                                $payload['dealer_name'] ?? '',
                                $payload['bill_number'] ?? '',
                                $this->amountText($payload['grand_total'] ?? 0),
                            ]),
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
        return $this->sendSimple(
            $to,
            $payload,
            'collection_template',
            [
                $payload['dealer_name'] ?? '',
                $this->amountText($payload['amount'] ?? 0),
                $this->dateText($payload['collection_date'] ?? ''),
                $this->amountText($payload['outstanding'] ?? 0),
            ],
            (string) ($payload['body'] ?? $this->fallbackCollectionCaption($payload)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message_id: string, media_id: ?string}
     */
    private function sendPaymentReminder(string $to, array $payload): array
    {
        $expected = $payload['expected_amount'] ?? null;

        return $this->sendSimple(
            $to,
            $payload,
            'payment_reminder_template',
            [
                $payload['dealer_name'] ?? '',
                $this->amountText($payload['outstanding'] ?? 0),
                $expected === null || $expected === ''
                    ? 'As discussed'
                    : $this->amountText($expected),
                $this->dateText($payload['follow_up_date'] ?? ''),
            ],
            (string) ($payload['body'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<mixed>  $bodyValues
     * @return array{message_id: string, media_id: ?string}
     */
    private function sendSimple(string $to, array $payload, string $templateConfigKey, array $bodyValues, string $fallbackBody): array
    {
        $template = trim((string) config('services.whatsapp.'.$templateConfigKey));
        $body = $fallbackBody !== '' ? $fallbackBody : (string) ($payload['body'] ?? '');

        if ($template !== '') {
            $sent = $this->cloud->sendMessage([
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => (string) config('services.whatsapp.template_language', 'en')],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => $this->textParams($bodyValues),
                    ]],
                ],
            ]);
        } else {
            $sent = $this->cloud->sendMessage([
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $body !== '' ? $body : '-',
                ],
            ]);
        }

        return [
            'message_id' => $sent['id'],
            'media_id' => null,
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<array{type: string, text: string}>
     */
    private function textParams(array $values): array
    {
        return array_map(function (mixed $value): array {
            $text = trim((string) $value);

            return ['type' => 'text', 'text' => $text !== '' ? $text : '-'];
        }, $values);
    }

    private function amountText(mixed $amount): string
    {
        return str_replace('₹', '', IndianCurrency::format($amount));
    }

    private function dateText(mixed $date): string
    {
        $raw = trim((string) $date);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->timezone('Asia/Kolkata')->format('d M Y');
        } catch (\Throwable) {
            return $raw;
        }
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

    private function logOutbound(WhatsAppOutboundMessage $message): void
    {
        $payload = $message->payload ?? [];

        Log::log(
            $message->isFailed() ? 'warning' : 'info',
            'WhatsApp outbound result',
            [
                'message_type' => $message->messageTypeLabel(),
                'source_type' => $message->source_type,
                'erp_reference' => $message->erp_reference,
                'dealer' => (string) ($payload['dealer_name'] ?? $message->dealerName()),
                'mobile' => $message->to_number,
                'status' => $message->status,
                'meta_message_id' => $message->meta_message_id,
                'meta_error' => $message->error,
            ],
        );
    }
}
