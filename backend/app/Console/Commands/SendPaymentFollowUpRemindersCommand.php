<?php

namespace App\Console\Commands;

use App\Services\PaymentFollowUps\PaymentFollowUpReminderService;
use Illuminate\Console\Command;

class SendPaymentFollowUpRemindersCommand extends Command
{
    protected $signature = 'payment-follow-ups:send-due-reminders';

    protected $description = 'Send due Payment Follow-up employee notifications and dealer WhatsApp reminders (idempotent).';

    public function handle(PaymentFollowUpReminderService $reminders): int
    {
        $stats = $reminders->sendDueReminders();

        $this->info('Processed '.$stats['processed']
            .' • employee sent '.$stats['employee_sent']
            .' • WhatsApp sent '.$stats['whatsapp_sent']
            .' • skipped '.$stats['skipped']
            .' • failed '.$stats['failed']);

        return self::SUCCESS;
    }
}
