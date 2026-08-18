<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class PaymentRequestSupportingDocument extends Model
{
    use SoftDeletes;

    public const DISK = 'local';

    public const MAX_SIZE_KB = 10240;

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    protected $fillable = [
        'payment_request_id',
        'original_file_name',
        'stored_file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function isPdf(): bool
    {
        return str_contains(strtolower($this->mime_type), 'pdf')
            || str_ends_with(strtolower($this->original_file_name), '.pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower($this->mime_type), 'image/');
    }

    public function absolutePath(): ?string
    {
        if (! Storage::disk(self::DISK)->exists($this->stored_file_path)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($this->stored_file_path);
    }

    public function humanFileSize(): string
    {
        $bytes = max(0, (int) $this->file_size);
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->original_file_name,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'file_size_label' => $this->humanFileSize(),
            'is_pdf' => $this->isPdf(),
            'is_image' => $this->isImage(),
            'uploaded_by' => $this->uploadedByUser?->name,
            'uploaded_at' => optional($this->created_at)?->timezone('Asia/Kolkata')->toIso8601String(),
            'view_url' => url('/api/director/payment-requests/'.$this->payment_request_id.'/supporting-documents/'.$this->id),
            'web_view_url' => route('payment-requests.supporting-documents.show', [
                'paymentRequest' => $this->payment_request_id,
                'supportingDocument' => $this->id,
            ]),
        ];
    }
}
