<?php

namespace App\Services\PaymentFollowUps;

use App\Models\Employee;
use App\Models\PaymentFollowUpCycle;
use App\Models\PaymentFollowUpEntry;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Services\Notifications\PaymentFollowUpPushNotifier;
use App\Services\WhatsApp\WhatsAppOutboundEnqueueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentFollowUpReminderService
{
    public function __construct(
        private readonly PaymentFollowUpPushNotifier $notifier,
        private readonly WhatsAppOutboundEnqueueService $whatsApp,
    ) {}

    /**
     * @return array{processed: int, employee_sent: int, whatsapp_sent: int, skipped: int, failed: int}
     */
    public function sendDueReminders(?Carbon $onDate = null): array
    {
        $today = ($onDate ?? Carbon::now(PaymentFollowUpStatus::TIMEZONE))->timezone(PaymentFollowUpStatus::TIMEZONE)->toDateString();

        $entryIds = PaymentFollowUpEntry::query()
            ->where('entry_type', PaymentFollowUpEntry::TYPE_FOLLOW_UP)
            ->whereDate('next_follow_up_date', '<=', $today)
            ->whereHas('cycle', fn ($query) => $query->where('status', PaymentFollowUpCycle::STATUS_OPEN))
            ->whereIn('id', function ($query): void {
                $query->selectRaw('MAX(id)')
                    ->from('payment_follow_up_entries')
                    ->where('entry_type', PaymentFollowUpEntry::TYPE_FOLLOW_UP)
                    ->groupBy('cycle_id');
            })
            ->pluck('id');

        $stats = [
            'processed' => 0,
            'employee_sent' => 0,
            'whatsapp_sent' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($entryIds as $entryId) {
            $result = $this->processEntry((int) $entryId);
            $stats['processed']++;
            $stats['employee_sent'] += $result['employee_sent'] ? 1 : 0;
            $stats['whatsapp_sent'] += $result['whatsapp_sent'] ? 1 : 0;
            $stats['skipped'] += $result['skipped'] ? 1 : 0;
            $stats['failed'] += $result['failed'] ? 1 : 0;
        }

        return $stats;
    }

    /**
     * @return array{employee_sent: bool, whatsapp_sent: bool, skipped: bool, failed: bool}
     */
    public function processEntry(int $entryId): array
    {
        return DB::transaction(function () use ($entryId): array {
            $entry = PaymentFollowUpEntry::query()
                ->whereKey($entryId)
                ->lockForUpdate()
                ->first();

            $result = [
                'employee_sent' => false,
                'whatsapp_sent' => false,
                'skipped' => false,
                'failed' => false,
            ];

            if ($entry === null || ! $entry->isFollowUp()) {
                $result['skipped'] = true;

                return $result;
            }

            $entry->loadMissing('cycle');
            if ($entry->cycle === null || ! $entry->cycle->isOpen()) {
                $result['skipped'] = true;

                return $result;
            }

            $today = PaymentFollowUpStatus::todayDate();
            $due = $entry->next_follow_up_date?->toDateString();
            if ($due === null || $due > $today) {
                $result['skipped'] = true;

                return $result;
            }

            if ($entry->employeeNotificationNeedsSend()) {
                if ($this->sendEmployeeNotification($entry)) {
                    $result['employee_sent'] = true;
                } else {
                    $result['failed'] = true;
                }
            }

            if ($entry->whatsappWasSent()) {
                return $result;
            }

            if ($entry->whatsappNeedsSend()) {
                $whatsApp = $this->sendWhatsApp($entry);
                if ($whatsApp === 'sent') {
                    $result['whatsapp_sent'] = true;
                } elseif ($whatsApp === 'failed') {
                    $result['failed'] = true;
                } else {
                    $result['skipped'] = true;
                }
            }

            return $result;
        });
    }

    private function sendEmployeeNotification(PaymentFollowUpEntry $entry): bool
    {
        if ($entry->employeeNotificationWasSent()) {
            return true;
        }

        try {
            $user = User::query()->where('employee_id', $entry->employee_id)->first()
                ?? Employee::query()->find($entry->employee_id)?->user;

            if (! $user instanceof User) {
                $entry->forceFill([
                    'employee_notification_status' => PaymentFollowUpEntry::REMINDER_FAILED,
                    'employee_notification_error' => 'Sales employee user account was not found.',
                ])->save();

                return false;
            }

            $this->notifier->notifyDue($entry, $user);

            $entry->forceFill([
                'employee_notification_status' => PaymentFollowUpEntry::REMINDER_SENT,
                'employee_notification_sent_at' => Carbon::now(PaymentFollowUpStatus::TIMEZONE),
                'employee_notification_error' => null,
            ])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Payment follow-up employee reminder failed: '.$e->getMessage(), [
                'entry_id' => $entry->id,
            ]);

            $entry->forceFill([
                'employee_notification_status' => PaymentFollowUpEntry::REMINDER_FAILED,
                'employee_notification_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return false;
        }
    }

    /**
     * @return 'sent'|'failed'|'skipped'
     */
    private function sendWhatsApp(PaymentFollowUpEntry $entry): string
    {
        if ($entry->whatsappWasSent()) {
            return 'sent';
        }

        $template = trim((string) config('services.whatsapp.payment_reminder_template'));
        if ($template === '') {
            $entry->forceFill([
                'whatsapp_status' => PaymentFollowUpEntry::REMINDER_SKIPPED,
                'whatsapp_error' => 'WhatsApp template payment_reminder is not configured yet.',
            ])->save();

            return 'skipped';
        }

        try {
            $message = $this->whatsApp->queuePaymentFollowUpReminder($entry);

            if ($message === null) {
                $entry->forceFill([
                    'whatsapp_status' => PaymentFollowUpEntry::REMINDER_SKIPPED,
                    'whatsapp_error' => 'WhatsApp reminder was not queued.',
                ])->save();

                return 'skipped';
            }

            if ($message->wasAcceptedByProvider()) {
                $entry->forceFill([
                    'whatsapp_status' => PaymentFollowUpEntry::REMINDER_SENT,
                    'whatsapp_sent_at' => $message->sent_at ?? Carbon::now(PaymentFollowUpStatus::TIMEZONE),
                    'whatsapp_error' => null,
                    'whatsapp_outbound_message_id' => $message->id,
                ])->save();

                return 'sent';
            }

            if ($message->isFailed()) {
                $entry->forceFill([
                    'whatsapp_status' => PaymentFollowUpEntry::REMINDER_FAILED,
                    'whatsapp_error' => $message->error ?: 'WhatsApp send failed.',
                    'whatsapp_outbound_message_id' => $message->id,
                ])->save();

                return 'failed';
            }

            $entry->forceFill([
                'whatsapp_status' => PaymentFollowUpEntry::REMINDER_PENDING,
                'whatsapp_error' => null,
                'whatsapp_outbound_message_id' => $message->id,
            ])->save();

            return 'skipped';
        } catch (Throwable $e) {
            Log::warning('Payment follow-up WhatsApp reminder failed: '.$e->getMessage(), [
                'entry_id' => $entry->id,
            ]);

            $entry->forceFill([
                'whatsapp_status' => PaymentFollowUpEntry::REMINDER_FAILED,
                'whatsapp_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return 'failed';
        }
    }
}
