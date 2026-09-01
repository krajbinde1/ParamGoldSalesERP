<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppOutboundMessage extends Model
{
    protected $table = 'whatsapp_outbound_messages';

    public const SOURCE_BILL = 'bill';

    public const SOURCE_COLLECTION = 'collection';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source_type',
        'source_id',
        'erp_reference',
        'to_number',
        'payload',
        'status',
        'attempts',
        'error',
        'meta_message_id',
        'meta_media_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public static function billReference(int $orderId): string
    {
        return 'WA-BILL-'.$orderId;
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

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
