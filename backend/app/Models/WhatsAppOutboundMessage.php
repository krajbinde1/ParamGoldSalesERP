<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppOutboundMessage extends Model
{
    protected $table = 'whatsapp_outbound_messages';

    public const SOURCE_BILL = 'bill';

    public const SOURCE_COLLECTION = 'collection';

    public const SEND_KIND_AUTO = 'auto';

    public const SEND_KIND_RESEND = 'resend';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $attributes = [
        'send_kind' => self::SEND_KIND_AUTO,
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected $fillable = [
        'source_type',
        'source_id',
        'send_kind',
        'erp_reference',
        'to_number',
        'payload',
        'status',
        'attempts',
        'error',
        'meta_message_id',
        'meta_media_id',
        'sent_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public static function billReference(int $orderId): string
    {
        return 'WA-BILL-'.$orderId;
    }

    public static function billResendReference(int $orderId): string
    {
        return 'WA-BILL-'.$orderId.'-R'.now('Asia/Kolkata')->format('YmdHis').'-'.bin2hex(random_bytes(3));
    }

    public static function collectionReference(int $collectionId): string
    {
        return 'WA-RCV-'.$collectionId;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function wasAcceptedByProvider(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_SENT => 'Sent',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'gray',
            self::STATUS_SENT => 'info',
            self::STATUS_DELIVERED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    public function messageTypeLabel(): string
    {
        if ($this->source_type === self::SOURCE_COLLECTION) {
            return 'Collection Received';
        }

        if ($this->send_kind === self::SEND_KIND_RESEND) {
            return 'Sales Bill Resend';
        }

        return 'Sales Bill';
    }

    public function dealerName(): string
    {
        return (string) ($this->payload['dealer_name'] ?? '—');
    }

    public function orderNo(): string
    {
        return (string) ($this->payload['order_no'] ?? '—');
    }

    public function billLabel(): string
    {
        return (string) ($this->payload['bill_number'] ?? $this->payload['filename'] ?? '—');
    }
}
