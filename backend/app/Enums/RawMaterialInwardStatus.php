<?php

namespace App\Enums;

enum RawMaterialInwardStatus: string
{
    /** @deprecated Legacy — new inwards are always Posted immediately */
    case Draft = 'draft';
    /** @deprecated Legacy approval status — migrated to Draft */
    case PendingApproval = 'pending_approval';
    /** @deprecated Legacy approval status — migrated to Draft */
    case Approved = 'approved';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft, self::PendingApproval, self::Approved => 'Draft',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft, self::PendingApproval, self::Approved => 'gray',
            self::Posted => 'success',
            self::Cancelled, self::Returned => 'danger',
        };
    }

    public function isEditable(): bool
    {
        // New workflow never creates drafts; legacy drafts remain editable only via service if needed.
        return in_array($this, [self::Draft, self::PendingApproval, self::Approved], true);
    }

    public function isImmutable(): bool
    {
        return in_array($this, [self::Posted, self::Returned, self::Cancelled], true);
    }

    public function canPost(): bool
    {
        return $this->isEditable();
    }

    /**
     * Active status filter options for UI (Draft/Pending removed from new workflow).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Posted->value => self::Posted->label(),
            self::Cancelled->value => self::Cancelled->label(),
            self::Returned->value => self::Returned->label(),
        ];
    }
}
