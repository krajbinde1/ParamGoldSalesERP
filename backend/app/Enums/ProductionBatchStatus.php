<?php

namespace App\Enums;

enum ProductionBatchStatus: string
{
    case Draft = 'draft';
    case MaterialChecked = 'material_checked';
    case DeviationPendingApproval = 'deviation_pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InProduction = 'in_production';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::MaterialChecked => 'Material Checked',
            self::DeviationPendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::InProduction => 'In Production',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::MaterialChecked => 'warning',
            self::DeviationPendingApproval => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::InProduction => 'info',
            self::Completed => 'success',
            self::Cancelled, self::Reversed => 'danger',
        };
    }

    public function isImmutable(): bool
    {
        return in_array($this, [self::Completed, self::Reversed], true);
    }

    public function isEditableDraft(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::MaterialChecked], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
