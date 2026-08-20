<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DealerApplicationDocument extends Model
{
    use SoftDeletes;

    public const DISK = 'local';

    public const MAX_SIZE_KB = 5120;

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public const TYPE_FERTILIZER_LICENSE = 'fertilizer_license';

    public const TYPE_SEED_LICENSE = 'seed_license';

    public const TYPE_INSECTICIDE_LICENSE = 'insecticide_license';

    public const TYPE_GST_CERTIFICATE = 'gst_certificate';

    public const TYPE_SHOP_UDYAM = 'shop_udyam_certificate';

    public const TYPE_OWNER_AADHAAR = 'owner_aadhaar';

    public const TYPE_OWNER_PAN = 'owner_pan';

    public const TYPE_SECURITY_DEPOSIT = 'security_deposit';

    public const TYPE_LABELS = [
        self::TYPE_FERTILIZER_LICENSE => 'Fertilizer License',
        self::TYPE_SEED_LICENSE => 'Seed License',
        self::TYPE_INSECTICIDE_LICENSE => 'Insecticide License',
        self::TYPE_GST_CERTIFICATE => 'GST Certificate',
        self::TYPE_SHOP_UDYAM => 'Shop / Udyam Certificate',
        self::TYPE_OWNER_AADHAAR => 'Owner Aadhaar Card',
        self::TYPE_OWNER_PAN => 'Owner PAN Card',
        self::TYPE_SECURITY_DEPOSIT => 'Security Deposit Document',
    ];

    protected $fillable = [
        'dealer_application_id',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(DealerApplication::class, 'dealer_application_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->document_type] ?? $this->document_type;
    }

    public function isPdf(): bool
    {
        return str_contains(strtolower((string) $this->mime_type), 'pdf')
            || str_ends_with(strtolower((string) $this->original_filename), '.pdf');
    }

    public function humanFileSize(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }

    public function absolutePath(): ?string
    {
        $path = str_replace('\\', '/', (string) $this->file_path);
        if ($path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $mime = strtolower((string) $this->mime_type);
        $relativePath = '/dealer-applications/'.$this->dealer_application_id.'/documents/'.$this->id;

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_name' => $this->typeLabel(),
            'original_filename' => $this->original_filename,
            'uploaded' => true,
            'mime_type' => $mime,
            'file_size' => (int) $this->file_size,
            'is_pdf' => str_contains($mime, 'pdf'),
            'is_image' => str_starts_with($mime, 'image/'),
            'uploaded_by' => $this->uploadedByUser?->name,
            'uploaded_at' => $this->uploaded_at?->timezone('Asia/Kolkata')?->toIso8601String(),
            'view_path' => $relativePath,
        ];
    }
}
