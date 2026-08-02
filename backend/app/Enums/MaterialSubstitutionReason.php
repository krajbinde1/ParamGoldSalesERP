<?php

namespace App\Enums;

enum MaterialSubstitutionReason: string
{
    case OriginalUnavailable = 'original_material_unavailable';
    case InsufficientStock = 'insufficient_stock';
    case QualityIssue = 'material_quality_issue';
    case ApprovedAlternate = 'approved_alternate_used';
    case UrgentProduction = 'urgent_production';
    case TrialProduction = 'trial_production';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OriginalUnavailable => 'Original material unavailable',
            self::InsufficientStock => 'Insufficient stock',
            self::QualityIssue => 'Material quality issue',
            self::ApprovedAlternate => 'Approved alternate used',
            self::UrgentProduction => 'Urgent production',
            self::TrialProduction => 'Trial production',
            self::Other => 'Other',
        };
    }

    public function requiresRemarks(): bool
    {
        return $this === self::Other;
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
