<?php

namespace App\Console\Commands;

use App\Services\PaymentFollowUps\PaymentFollowUpCommitmentService;
use App\Services\PaymentFollowUps\PaymentFollowUpReminderService;
use Illuminate\Console\Command;

class SendPaymentFollowUpRemindersCommand extends Command
{
    protected $signature = 'payment-follow-ups:send-due-reminders';

    protected $description = 'Send due Payment Follow-up employee notifications and dealer WhatsApp reminders (idempotent). Retry failed payment_commitment messages.';

    public function handle(
        PaymentFollowUpReminderService $reminders,
        PaymentFollowUpCommitmentService $commitments,
    ): int {
        $stats = $reminders->sendDueReminders();
        $commitmentStats = $commitments->retryUnsent();

        $this->info('Processed '.$stats['processed']
            .' • employee sent '.$stats['employee_sent']
            .' • WhatsApp sent '.$stats['whatsapp_sent']
            .' • skipped '.$stats['skipped']
            .' • failed '.$stats['failed']);

        $this->info('Commitments processed '.$commitmentStats['processed']
            .' • sent '.$commitmentStats['sent']
            .' • skipped '.$commitmentStats['skipped']
            .' • failed '.$commitmentStats['failed']);

        return self::SUCCESS;
    }
}
