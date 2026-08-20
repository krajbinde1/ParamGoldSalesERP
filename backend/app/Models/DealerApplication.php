<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class DealerApplication extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_MANAGER = 'pending_manager_approval';

    public const STATUS_PENDING_ADMIN = 'pending_admin_approval';

    public const STATUS_CORRECTION_REQUIRED = 'correction_required';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPROVED = 'approved';

    public const GST_REGEX = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[A-Z0-9]{1}Z[A-Z0-9]{1}$/';

    public const MOBILE_REGEX = '/^[6-9][0-9]{9}$/';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PENDING_MANAGER => 'Pending Manager Approval',
        self::STATUS_PENDING_ADMIN => 'Pending Admin Approval',
        self::STATUS_CORRECTION_REQUIRED => 'Correction Required',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_APPROVED => 'Active',
    ];

    protected $fillable = [
        'employee_id',
        'firm_name',
        'owner_name',
        'mobile',
        'gst_no',
        'state',
        'district',
        'taluka',
        'village',
        'address',
        'latitude',
        'longitude',
        'status',
        'duplicate_warning',
        'submitted_at',
        'manager_id',
        'manager_name',
        'manager_approved_at',
        'manager_remark',
        'admin_id',
        'admin_name',
        'admin_approved_at',
        'admin_remark',
        'last_action',
        'last_action_by',
        'last_action_by_name',
        'last_action_at',
        'last_action_remark',
        'dealer_id',
        'party_id',
    ];

    protected function casts(): array
    {
        return [
            'duplicate_warning' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'submitted_at' => 'datetime',
            'manager_approved_at' => 'datetime',
            'admin_approved_at' => 'datetime',
            'last_action_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DealerApplicationDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DealerApplicationEvent::class)->orderBy('id');
    }

    public function statusLabel(): string
    {
        if ($this->status === self::STATUS_APPROVED) {
            return 'Active';
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function locationLabel(): string
    {
        return collect([$this->village, $this->taluka, $this->district, $this->state])
            ->filter(fn ($part): bool => filled($part))
            ->implode(', ');
    }

    public function isEditableByEmployee(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_CORRECTION_REQUIRED,
        ], true);
    }

    public function canSubmit(): bool
    {
        return $this->isEditableByEmployee();
    }

    public function nextStatusAfterResubmit(): string
    {
        if ($this->status === self::STATUS_CORRECTION_REQUIRED
            && $this->manager_approved_at !== null
            && $this->last_action === DealerApplicationEvent::SENT_BACK
            && $this->admin_id !== null) {
            return self::STATUS_PENDING_ADMIN;
        }

        return self::STATUS_PENDING_MANAGER;
    }

    /**
     * @return list<string>
     */
    public function missingDocumentTypes(): array
    {
        $uploaded = $this->documents()
            ->pluck('document_type')
            ->filter()
            ->all();

        return array_values(array_diff(array_keys(DealerApplicationDocument::TYPE_LABELS), $uploaded));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documentSlots(): array
    {
        $byType = $this->documents->keyBy('document_type');

        $slots = [];
        foreach (DealerApplicationDocument::TYPE_LABELS as $type => $label) {
            $document = $byType->get($type);
            if ($document instanceof DealerApplicationDocument) {
                $slots[] = $document->toApiArray();

                continue;
            }

            $slots[] = [
                'id' => null,
                'document_type' => $type,
                'document_name' => $label,
                'original_filename' => null,
                'uploaded' => false,
                'mime_type' => null,
                'file_size' => 0,
                'is_pdf' => false,
                'is_image' => false,
                'uploaded_by' => null,
                'uploaded_at' => null,
                'view_path' => null,
            ];
        }

        return $slots;
    }

    public function recordEvent(
        string $eventType,
        ?User $actor = null,
        ?string $remark = null,
        array $payload = [],
    ): DealerApplicationEvent {
        return $this->events()->create([
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'remark' => $remark,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'item_type' => 'application',
            'firm_name' => $this->firm_name,
            'owner_name' => $this->owner_name,
            'mobile' => $this->mobile,
            'gst_no' => $this->gst_no,
            'state' => $this->state,
            'district' => $this->district,
            'taluka' => $this->taluka,
            'village' => $this->village,
            'location' => $this->locationLabel(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'duplicate_warning' => (bool) $this->duplicate_warning,
            'submitted_at' => self::iso($this->submitted_at),
            'created_at' => self::iso($this->created_at),
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->full_name,
            'employee_code' => $this->employee?->employee_code,
            'dealer_id' => $this->dealer_id,
            'dealer_code' => $this->dealer?->dealer_code,
            'party_id' => $this->party_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'can_edit' => $this->isEditableByEmployee(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetailArray(): array
    {
        return array_merge($this->toListArray(), [
            'address' => $this->address,
            'manager_id' => $this->manager_id,
            'manager_name' => $this->manager_name,
            'manager_approved_at' => self::iso($this->manager_approved_at),
            'manager_remark' => $this->manager_remark,
            'admin_id' => $this->admin_id,
            'admin_name' => $this->admin_name,
            'admin_approved_at' => self::iso($this->admin_approved_at),
            'admin_remark' => $this->admin_remark,
            'last_action' => $this->last_action,
            'last_action_by_name' => $this->last_action_by_name,
            'last_action_at' => self::iso($this->last_action_at),
            'last_action_remark' => $this->last_action_remark,
            'documents' => $this->documentSlots(),
            'timeline' => $this->events->map(fn (DealerApplicationEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'label' => $this->eventLabel($event),
                'actor_name' => $event->actor_name,
                'remark' => $event->remark,
                'payload' => $event->payload,
                'occurred_at' => self::iso($event->created_at),
            ])->values()->all(),
        ]);
    }

    public function eventLabel(DealerApplicationEvent $event): string
    {
        return match ($event->event_type) {
            DealerApplicationEvent::CREATED => 'Created by Employee',
            DealerApplicationEvent::SUBMITTED => 'Submitted for Approval',
            DealerApplicationEvent::RESUBMITTED => 'Resubmitted for Approval',
            DealerApplicationEvent::MANAGER_APPROVED => 'Manager Approved',
            DealerApplicationEvent::ADMIN_APPROVED => 'Admin Approved',
            DealerApplicationEvent::DEALER_CODE_GENERATED => 'Dealer Code Generated',
            DealerApplicationEvent::PARTY_CREATED => 'Party Created',
            DealerApplicationEvent::REJECTED => 'Rejected',
            DealerApplicationEvent::SENT_BACK => 'Sent Back for Correction',
            default => $event->event_type,
        };
    }

    public static function iso(?Carbon $value): ?string
    {
        return $value?->timezone('Asia/Kolkata')?->toIso8601String();
    }
}
