<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentFollowUpEntry extends Model
{
    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_PAYMENT_RECEIVED = 'payment_received';

    public const REMINDER_PENDING = 'pending';

    public const REMINDER_SENT = 'sent';

    public const REMINDER_FAILED = 'failed';

    public const REMINDER_SKIPPED = 'skipped';

    public const COMMITMENT_PENDING = 'pending';

    public const COMMITMENT_KEPT = 'kept';

    public const COMMITMENT_MISSED = 'missed';

    protected $fillable = [
        'cycle_id',
        'dealer_id',
        'employee_id',
        'created_by',
        'entry_type',
        'followed_up_at',
        'remark',
        'outstanding_at_time',
        'expected_amount',
        'next_follow_up_date',
        'employee_notification_status',
        'employee_notification_sent_at',
        'employee_notification_error',
        'whatsapp_status',
        'whatsapp_sent_at',
        'whatsapp_error',
        'whatsapp_outbound_message_id',
        'commitment_whatsapp_status',
        'commitment_whatsapp_sent_at',
        'commitment_whatsapp_error',
        'commitment_whatsapp_outbound_message_id',
        'collection_id',
    ];

    protected function casts(): array
    {
        return [
            'followed_up_at' => 'datetime',
            'outstanding_at_time' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'next_follow_up_date' => 'date',
            'employee_notification_sent_at' => 'datetime',
            'whatsapp_sent_at' => 'datetime',
            'commitment_whatsapp_sent_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaymentFollowUpCycle::class, 'cycle_id');
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function whatsappOutboundMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppOutboundMessage::class, 'whatsapp_outbound_message_id');
    }

    public function commitmentWhatsappOutboundMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppOutboundMessage::class, 'commitment_whatsapp_outbound_message_id');
    }

    public function isFollowUp(): bool
    {
        return $this->entry_type === self::TYPE_FOLLOW_UP;
    }

    public function employeeNotificationNeedsSend(): bool
    {
        return in_array($this->employee_notification_status, [
            self::REMINDER_PENDING,
            self::REMINDER_FAILED,
        ], true);
    }

    public function whatsappNeedsSend(): bool
    {
        return in_array($this->whatsapp_status, [
            self::REMINDER_PENDING,
            self::REMINDER_FAILED,
            self::REMINDER_SKIPPED,
        ], true);
    }

    public function employeeNotificationWasSent(): bool
    {
        return $this->employee_notification_status === self::REMINDER_SENT;
    }

    public function whatsappWasSent(): bool
    {
        return $this->whatsapp_status === self::REMINDER_SENT;
    }

    public function commitmentWhatsAppNeedsSend(): bool
    {
        return in_array($this->commitment_whatsapp_status, [
            self::REMINDER_PENDING,
            self::REMINDER_FAILED,
            self::REMINDER_SKIPPED,
        ], true);
    }

    public function commitmentWhatsAppWasSent(): bool
    {
        return $this->commitment_whatsapp_status === self::REMINDER_SENT;
    }
}
