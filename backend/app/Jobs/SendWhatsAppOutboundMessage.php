<?php

namespace App\Jobs;

use App\Models\WhatsAppOutboundMessage;
use App\Services\WhatsApp\WhatsAppOutboundSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppOutboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 120;

    public function __construct(public int $messageId) {}

    public function uniqueId(): string
    {
        return 'whatsapp-outbound-'.$this->messageId;
    }

    public function handle(WhatsAppOutboundSender $sender): void
    {
        $message = WhatsAppOutboundMessage::query()->find($this->messageId);
        if ($message === null || ! $message->isPending()) {
            return;
        }

        $sender->send($message);
    }

    public function failed(?Throwable $exception): void
    {
        $message = WhatsAppOutboundMessage::query()->find($this->messageId);
        if ($message === null || $message->isSent()) {
            return;
        }

        $error = $exception?->getMessage() ?: 'WhatsApp send job failed.';
        $message->update([
            'status' => WhatsAppOutboundMessage::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
        ]);

        Log::error('WhatsApp outbound job failed: '.$error, [
            'message_id' => $this->messageId,
        ]);
    }
}
