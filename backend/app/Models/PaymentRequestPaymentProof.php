<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PaymentRequestPaymentProof extends Model
{
    public const DISK = 'public';

    public const MAX_SIZE_KB = 10240;

    public const MAX_FILES = 10;

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /**
     * @param  list<UploadedFile>  $files
     */
    public static function assertUploadedFiles(array $files): void
    {
        $files = array_values(array_filter(
            $files,
            fn ($file): bool => $file instanceof UploadedFile,
        ));

        if ($files === []) {
            throw ValidationException::withMessages([
                'payment_proof' => ['Payment screenshot / proof is required.'],
            ]);
        }

        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages([
                'payment_proof' => ['You may upload at most '.self::MAX_FILES.' payment proof files.'],
            ]);
        }

        $pdfCount = 0;
        $imageCount = 0;
        $maxBytes = self::MAX_SIZE_KB * 1024;

        foreach ($files as $file) {
            if (! $file->isValid()) {
                throw ValidationException::withMessages([
                    'payment_proof' => ['One or more uploaded files are invalid.'],
                ]);
            }

            if ((int) $file->getSize() > $maxBytes) {
                throw ValidationException::withMessages([
                    'payment_proof' => ['Each payment proof must be 10 MB or smaller.'],
                ]);
            }

            $isPdf = self::uploadedFileIsPdf($file);
            $isImage = self::uploadedFileIsImage($file);

            if (! $isPdf && ! $isImage) {
                throw ValidationException::withMessages([
                    'payment_proof' => ['Only PDF, JPG, JPEG, PNG, and WEBP files are allowed.'],
                ]);
            }

            if ($isPdf) {
                $pdfCount++;
            }
            if ($isImage && ! $isPdf) {
                $imageCount++;
            }
        }

        if ($pdfCount > 0 && $imageCount > 0) {
            throw ValidationException::withMessages([
                'payment_proof' => ['Upload either one PDF or one or more images. A PDF cannot be combined with images.'],
            ]);
        }

        if ($pdfCount > 1) {
            throw ValidationException::withMessages([
                'payment_proof' => ['Only one PDF file is allowed. For multiple files, upload images.'],
            ]);
        }
    }

    public static function uploadedFileIsPdf(UploadedFile $file): bool
    {
        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return str_contains($mime, 'pdf') || $extension === 'pdf';
    }

    public static function uploadedFileIsImage(UploadedFile $file): bool
    {
        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)
            || in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    protected $fillable = [
        'payment_request_id',
        'original_file_name',
        'stored_file_path',
        'mime_type',
        'file_size',
        'sort_order',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'sort_order' => 'integer',
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

    public function isPdf(): bool
    {
        return str_contains(strtolower((string) $this->mime_type), 'pdf')
            || str_ends_with(strtolower((string) $this->original_file_name), '.pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower((string) $this->mime_type), 'image/')
            || preg_match('/\.(jpe?g|png|webp)$/i', (string) $this->original_file_name) === 1;
    }

    public function publicUrl(): ?string
    {
        if (blank($this->stored_file_path)) {
            return null;
        }

        return url('storage/'.ltrim(str_replace('\\', '/', (string) $this->stored_file_path), '/'));
    }

    public function absolutePath(): ?string
    {
        $path = str_replace('\\', '/', (string) $this->stored_file_path);
        if ($path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($path);
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

    public function resolvedMimeType(): string
    {
        $mime = strtolower(trim((string) $this->mime_type));
        if (in_array($mime, self::ALLOWED_MIMES, true)) {
            return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
        }

        $name = strtolower((string) $this->original_file_name);
        if (str_ends_with($name, '.pdf')) {
            return 'application/pdf';
        }
        if (str_ends_with($name, '.png')) {
            return 'image/png';
        }
        if (str_ends_with($name, '.webp')) {
            return 'image/webp';
        }
        if (str_ends_with($name, '.jpg') || str_ends_with($name, '.jpeg')) {
            return 'image/jpeg';
        }

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * @return array{view: string, download: string}
     */
    public function webUrls(): array
    {
        $params = [
            'paymentRequest' => $this->payment_request_id,
            'paymentProof' => $this->id,
        ];

        $view = Route::has('filament.admin.payment-requests.payment-proofs.show')
            ? route('filament.admin.payment-requests.payment-proofs.show', $params)
            : route('payment-requests.payment-proofs.show', $params);

        return [
            'view' => $view,
            'download' => $view.(str_contains($view, '?') ? '&' : '?').'download=1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $relativePath = '/director/payment-requests/'.$this->payment_request_id.'/payment-proofs/'.$this->id;
        $mime = $this->resolvedMimeType();
        $urls = $this->webUrls();

        return [
            'id' => $this->id,
            'file_name' => $this->original_file_name,
            'mime_type' => $mime,
            'file_size' => (int) $this->file_size,
            'file_size_label' => $this->humanFileSize(),
            'is_pdf' => str_contains($mime, 'pdf'),
            'is_image' => str_starts_with($mime, 'image/'),
            'view_path' => $relativePath,
            'view_url' => url('/api'.$relativePath),
            'download_url' => url('/api'.$relativePath.'?download=1'),
            'public_url' => $this->publicUrl(),
            'web_view_url' => $urls['view'],
            'web_download_url' => $urls['download'],
        ];
    }
}
