<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Models\DealerCreditLimit;
use App\Models\DealerCreditLimitAudit;
use App\Models\User;
use App\Support\IndianCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DealerCreditLimitWriter
{
    public function __construct(
        private readonly DealerCreditExposureService $exposure,
        private readonly DealerCreditLimitNotifier $notifier,
        private readonly DealerAccessService $access,
    ) {}

    public function canManage(User $user, Dealer $dealer): bool
    {
        if ($user->isAdminUser() || $user->isDirectorUser()) {
            return true;
        }

        return $user->isManagerUser() && $this->access->canAccessDealer($user, $dealer);
    }

    public function assertCanManage(User $user, Dealer $dealer): void
    {
        if (! $this->canManage($user, $dealer)) {
            abort(403, 'You are not authorized to manage this dealer credit limit.');
        }
    }

    public function setBase(Dealer $dealer, User $actor, float $amount, string $remark): DealerCreditLimit
    {
        $this->assertCanManage($actor, $dealer);
        $amount = round($amount, 2);
        $remark = trim($remark);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Credit limit amount is required.',
            ]);
        }

        if ($remark === '') {
            throw ValidationException::withMessages([
                'remark' => 'Remark is required.',
            ]);
        }

        return DB::transaction(function () use ($dealer, $actor, $amount, $remark): DealerCreditLimit {
            $this->lockDealer($dealer);
            $profile = $this->lockedProfile($dealer);
            $previous = $profile->base_limit !== null ? round((float) $profile->base_limit, 2) : null;
            $action = $previous === null ? DealerCreditLimitAudit::ACTION_SET : DealerCreditLimitAudit::ACTION_EDIT;

            $profile->fill([
                'base_limit' => $amount,
                'remark' => $remark,
                'set_by_user_id' => $actor->id,
                'set_by_role' => $this->roleFor($actor),
            ]);
            $profile->save();

            $effective = $this->exposure->effectiveLimitAmount($profile->fresh());
            $this->audit(
                dealer: $dealer,
                profile: $profile,
                action: $action,
                previousBase: $previous,
                newBase: $amount,
                extensionAmount: $this->exposure->activeExtensionAmount($profile),
                effective: $effective,
                validUntil: $this->exposure->activeExtensionAmount($profile) > 0
                    ? $profile->extension_valid_until?->toDateString()
                    : null,
                remark: $remark,
                actor: $actor,
            );

            $formatted = IndianCurrency::format($amount);
            $body = $action === DealerCreditLimitAudit::ACTION_SET
                ? 'Credit limit for '.$dealer->firm_name.' has been set to '.$formatted.'.'
                : 'Credit limit for '.$dealer->firm_name.' has been updated to '.$formatted.'.';

            $this->notifier->notifySalesEmployee(
                $dealer,
                $action === DealerCreditLimitAudit::ACTION_SET ? 'credit_limit_set' : 'credit_limit_updated',
                'Credit limit updated',
                $body,
            );

            return $profile->fresh(['updatedBy']);
        });
    }

    public function extend(Dealer $dealer, User $actor, float $amount, string $validUntil, string $remark): DealerCreditLimit
    {
        $this->assertCanManage($actor, $dealer);
        $amount = round($amount, 2);
        $remark = trim($remark);
        $until = Carbon::parse($validUntil, 'Asia/Kolkata')->startOfDay();

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Extension amount is required.',
            ]);
        }

        if ($remark === '') {
            throw ValidationException::withMessages([
                'remark' => 'Remark is required.',
            ]);
        }

        if ($until->lt(Carbon::now('Asia/Kolkata')->startOfDay())) {
            throw ValidationException::withMessages([
                'valid_until' => 'Extension valid until must be today or a future date.',
            ]);
        }

        return DB::transaction(function () use ($dealer, $actor, $amount, $until, $remark): DealerCreditLimit {
            $this->lockDealer($dealer);
            $profile = $this->lockedProfile($dealer);

            if ($profile->base_limit === null) {
                throw ValidationException::withMessages([
                    'amount' => 'Set a base credit limit before adding an extension.',
                ]);
            }

            $previousBase = round((float) $profile->base_limit, 2);
            $profile->fill([
                'extension_amount' => $amount,
                'extension_valid_until' => $until->toDateString(),
                'extension_expired_at' => null,
                'extension_remark' => $remark,
                'set_by_user_id' => $actor->id,
                'set_by_role' => $this->roleFor($actor),
            ]);
            $profile->save();

            $fresh = $profile->fresh();
            $effective = $this->exposure->effectiveLimitAmount($fresh);
            $this->audit(
                dealer: $dealer,
                profile: $fresh,
                action: DealerCreditLimitAudit::ACTION_EXTEND,
                previousBase: $previousBase,
                newBase: $previousBase,
                extensionAmount: $amount,
                effective: $effective,
                validUntil: $until->toDateString(),
                remark: $remark,
                actor: $actor,
            );

            $this->notifier->notifySalesEmployee(
                $dealer,
                'credit_limit_extended',
                'Credit limit extended',
                'Credit limit for '.$dealer->firm_name.' has been extended by '.IndianCurrency::format($amount).' until '.$until->timezone('Asia/Kolkata')->format('j M Y').'.',
            );

            return $fresh->load('updatedBy');
        });
    }

    public function expireExtension(Dealer $dealer, User $actor, string $remark): DealerCreditLimit
    {
        $this->assertCanManage($actor, $dealer);
        $remark = trim($remark);

        if ($remark === '') {
            throw ValidationException::withMessages([
                'remark' => 'Remark is required.',
            ]);
        }

        return DB::transaction(function () use ($dealer, $actor, $remark): DealerCreditLimit {
            $this->lockDealer($dealer);
            $profile = DealerCreditLimit::query()
                ->where('dealer_id', $dealer->id)
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                throw ValidationException::withMessages([
                    'remark' => 'There is no active temporary extension to remove.',
                ]);
            }

            $stored = round((float) $profile->extension_amount, 2);

            if ($stored <= 0.0 || $profile->extension_expired_at !== null || $this->exposure->extensionIsExpired($profile)) {
                if ($stored > 0.0 && $profile->extension_expired_at === null && $this->exposure->extensionIsExpired($profile)) {
                    $this->recordAutomaticExpiry($dealer);
                }

                throw ValidationException::withMessages([
                    'remark' => 'There is no active temporary extension to remove.',
                ]);
            }

            $previousBase = $profile->base_limit !== null ? round((float) $profile->base_limit, 2) : null;
            $validUntil = $profile->extension_valid_until?->toDateString();
            $profile->fill([
                'extension_amount' => 0,
                'extension_valid_until' => null,
                'extension_expired_at' => Carbon::now('Asia/Kolkata'),
                'extension_remark' => $remark,
                'set_by_user_id' => $actor->id,
                'set_by_role' => $this->roleFor($actor),
            ]);
            $profile->save();

            $fresh = $profile->fresh();
            $effective = $this->exposure->effectiveLimitAmount($fresh);
            $this->audit(
                dealer: $dealer,
                profile: $fresh,
                action: DealerCreditLimitAudit::ACTION_EXPIRE,
                previousBase: $previousBase,
                newBase: $previousBase,
                extensionAmount: $stored,
                effective: $effective,
                validUntil: $validUntil,
                remark: $remark,
                actor: $actor,
            );

            $limitLabel = $effective !== null ? IndianCurrency::format($effective) : 'the base limit';
            $this->notifier->notifySalesEmployee(
                $dealer,
                'credit_limit_expired',
                'Credit extension removed',
                'Temporary credit extension for '.$dealer->firm_name.' was removed. Effective limit is now '.$limitLabel.'.',
            );

            return $fresh->load('updatedBy');
        });
    }

    /**
     * After the valid-until date, the extension stops counting. The extend audit is kept
     * and one expire audit is added the first time the expiry is observed.
     */
    public function recordAutomaticExpiry(Dealer $dealer): void
    {
        $existing = DealerCreditLimit::query()->where('dealer_id', $dealer->id)->first();
        if ($existing === null || $existing->extension_expired_at !== null) {
            return;
        }

        if (! $this->exposure->extensionIsExpired($existing)) {
            return;
        }

        DB::transaction(function () use ($dealer): void {
            $profile = DealerCreditLimit::query()
                ->where('dealer_id', $dealer->id)
                ->lockForUpdate()
                ->first();

            if ($profile === null || $profile->extension_expired_at !== null) {
                return;
            }

            if (! $this->exposure->extensionIsExpired($profile)) {
                return;
            }

            $stored = round((float) $profile->extension_amount, 2);
            $previousBase = $profile->base_limit !== null ? round((float) $profile->base_limit, 2) : null;
            $validUntil = $profile->extension_valid_until?->toDateString();
            $profile->extension_expired_at = Carbon::now('Asia/Kolkata');
            $profile->save();

            $fresh = $profile->fresh();
            $effective = $this->exposure->effectiveLimitAmount($fresh);
            $this->audit(
                dealer: $dealer,
                profile: $fresh,
                action: DealerCreditLimitAudit::ACTION_EXPIRE,
                previousBase: $previousBase,
                newBase: $previousBase,
                extensionAmount: $stored,
                effective: $effective,
                validUntil: $validUntil,
                remark: 'Temporary extension expired.',
                actor: null,
            );

            $dateLabel = $validUntil
                ? Carbon::parse($validUntil, 'Asia/Kolkata')->format('j M Y')
                : Carbon::now('Asia/Kolkata')->format('j M Y');
            $limitLabel = $effective !== null ? IndianCurrency::format($effective) : 'the base limit';

            $this->notifier->notifySalesEmployee(
                $dealer,
                'credit_limit_expired',
                'Credit extension expired',
                'Temporary credit extension for '.$dealer->firm_name.' expired on '.$dateLabel.'. Effective limit is now '.$limitLabel.'.',
            );
        });
    }

    private function lockDealer(Dealer $dealer): void
    {
        Dealer::query()->whereKey($dealer->id)->lockForUpdate()->first();
    }

    private function lockedProfile(Dealer $dealer): DealerCreditLimit
    {
        $profile = DealerCreditLimit::query()
            ->where('dealer_id', $dealer->id)
            ->lockForUpdate()
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        return DealerCreditLimit::query()->create([
            'dealer_id' => $dealer->id,
            'extension_amount' => 0,
        ]);
    }

    private function audit(
        Dealer $dealer,
        DealerCreditLimit $profile,
        string $action,
        ?float $previousBase,
        ?float $newBase,
        float $extensionAmount,
        ?float $effective,
        ?string $validUntil,
        string $remark,
        ?User $actor,
    ): DealerCreditLimitAudit {
        return DealerCreditLimitAudit::query()->create([
            'dealer_id' => $dealer->id,
            'action' => $action,
            'previous_base' => $previousBase,
            'new_base' => $newBase,
            'extension_amount' => round($extensionAmount, 2),
            'effective_limit' => $effective,
            'valid_until' => $validUntil,
            'remark' => $remark,
            'changed_by_user_id' => $actor?->id,
            'changed_by_role' => $this->roleFor($actor),
        ]);
    }

    private function roleFor(?User $user): string
    {
        if ($user === null) {
            return 'system';
        }

        if ($user->isAdminUser()) {
            return 'admin';
        }

        if ($user->isDirectorUser()) {
            return 'director';
        }

        if ($user->isManagerUser()) {
            return 'manager';
        }

        return (string) ($user->role ?: 'user');
    }
}
