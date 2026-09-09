<?php

namespace App\Services\PaymentFollowUps;

use App\Models\PaymentFollowUpEntry;
use App\Models\WhatsAppOutboundMessage;
use App\Services\WhatsApp\WhatsAppOutboundEnqueueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentFollowUpCommitmentService
{
    public function __construct(
        private readonly WhatsAppOutboundEnqueueService $whatsApp,
    ) {}

    /**
     * @return array{processed: int, sent: int, skipped: int, failed: int}
     */
    public function retryUnsent(): array
    {
        $templateConfigured = trim((string) config('services.whatsapp.payment_commitment_template')) !== '';

        $entryIds = PaymentFollowUpEntry::query()
            ->where('entry_type', PaymentFollowUpEntry::TYPE_FOLLOW_UP)
            ->where(function ($query) use ($templateConfigured): void {
                $query->whereIn('commitment_whatsapp_status', [
                    PaymentFollowUpEntry::REMINDER_PENDING,
                    PaymentFollowUpEntry::REMINDER_FAILED,
                ]);

                if ($templateConfigured) {
                    $query->orWhere('commitment_whatsapp_status', PaymentFollowUpEntry::REMINDER_SKIPPED);
                }
            })
            ->pluck('id');

        $stats = [
            'processed' => 0,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($entryIds as $entryId) {
            $result = $this->processEntry((int) $entryId);
            $stats['processed']++;
            $stats[$result]++;
        }

        return $stats;
    }

    /**
     * @return 'sent'|'failed'|'skipped'
     */
    public function sendForEntry(PaymentFollowUpEntry $entry): string
    {
        return $this->processEntry((int) $entry->id);
    }

    public function syncFromOutbound(WhatsAppOutboundMessage $message): void
    {
        if ($message->source_type !== WhatsAppOutboundMessage::SOURCE_PAYMENT_COMMITMENT) {
            return;
        }

        $entry = PaymentFollowUpEntry::query()->find((int) $message->source_id);
        if ($entry === null || ! $entry->isFollowUp()) {
            return;
        }

        $this->applyOutboundStatus($entry, $message);
    }

    /**
     * @return 'sent'|'failed'|'skipped'
     */
    public function processEntry(int $entryId): string
    {
        return DB::transaction(function () use ($entryId): string {
            $entry = PaymentFollowUpEntry::query()
                ->whereKey($entryId)
                ->lockForUpdate()
                ->first();

            if ($entry === null || ! $entry->isFollowUp()) {
                return 'skipped';
            }

            if ($entry->commitmentWhatsAppWasSent()) {
                return 'sent';
            }

            if (! $entry->commitmentWhatsAppNeedsSend()) {
                return 'skipped';
            }

            $template = trim((string) config('services.whatsapp.payment_commitment_template'));
            if ($template === '') {
                $entry->forceFill([
                    'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_SKIPPED,
                    'commitment_whatsapp_error' => 'WhatsApp template payment_commitment is not configured yet.',
                ])->save();

                return 'skipped';
            }

            try {
                $message = $this->whatsApp->queuePaymentFollowUpCommitment($entry);
                $message = $message?->fresh() ?? $message;

                if ($message === null) {
                    $entry->forceFill([
                        'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_SKIPPED,
                        'commitment_whatsapp_error' => 'WhatsApp commitment was not queued.',
                    ])->save();

                    return 'skipped';
                }

                return $this->applyOutboundStatus($entry, $message);
            } catch (Throwable $e) {
                Log::warning('Payment follow-up WhatsApp commitment failed: '.$e->getMessage(), [
                    'entry_id' => $entry->id,
                ]);

                $entry->forceFill([
                    'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_FAILED,
                    'commitment_whatsapp_error' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();

                return 'failed';
            }
        });
    }

    /**
     * @return 'sent'|'failed'|'skipped'
     */
    private function applyOutboundStatus(PaymentFollowUpEntry $entry, WhatsAppOutboundMessage $message): string
    {
        if ($message->wasAcceptedByProvider()) {
            $entry->forceFill([
                'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_SENT,
                'commitment_whatsapp_sent_at' => $message->sent_at ?? Carbon::now(PaymentFollowUpStatus::TIMEZONE),
                'commitment_whatsapp_error' => null,
                'commitment_whatsapp_outbound_message_id' => $message->id,
            ])->save();

            return 'sent';
        }

        if ($message->isFailed()) {
            $entry->forceFill([
                'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_FAILED,
                'commitment_whatsapp_error' => $message->error ?: 'WhatsApp send failed.',
                'commitment_whatsapp_outbound_message_id' => $message->id,
            ])->save();

            return 'failed';
        }

        $entry->forceFill([
            'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_PENDING,
            'commitment_whatsapp_error' => null,
            'commitment_whatsapp_outbound_message_id' => $message->id,
        ])->save();

        return 'skipped';
    }
}
