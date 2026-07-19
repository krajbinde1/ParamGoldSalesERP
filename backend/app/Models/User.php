<?php

namespace App\Models;

use App\Enums\FilamentJobRole;
use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'login_id',
        'password',
        'must_change_password',
        'role',
        'job_role',
        'password_reset_by',
        'password_reset_at',
        'login_id_changed_by',
        'login_id_changed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_reset_at' => 'datetime',
            'login_id_changed_at' => 'datetime',
        ];
    }

    public function passwordResetBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'password_reset_by');
    }

    public function loginIdChangedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'login_id_changed_by');
    }

    public function accountStatusLabel(): string
    {
        if ($this->employee === null) {
            return 'No Employee Linked';
        }

        return $this->employee->status ? 'Active' : 'Inactive';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isLinkedToActiveEmployee(): bool
    {
        if ($this->employee_id === null) {
            return false;
        }

        return Employee::query()->whereKey($this->employee_id)->exists();
    }

    public function roleEnum(): UserRole
    {
        return UserRole::tryFromMixed($this->role);
    }

    public function hasRole(UserRole|string $role): bool
    {
        $expected = $role instanceof UserRole ? $role->value : $role;

        return $this->role === $expected;
    }

    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function canLoginToMobile(): bool
    {
        if ($this->employee === null || $this->employee->status !== true) {
            return false;
        }

        return in_array($this->role, UserRole::mobileValues(), true);
    }

    public function resolvedJobRole(): ?string
    {
        if (filled($this->job_role)) {
            return trim((string) $this->job_role);
        }

        if (filled($this->employee?->designation)) {
            return trim((string) $this->employee->designation);
        }

        return null;
    }

    public function hasOrdersOnlyFilamentAccess(): bool
    {
        return FilamentJobRole::isOrdersOnlyAccess($this->resolvedJobRole());
    }

    public function hasProductionManagerJobRole(): bool
    {
        return FilamentJobRole::isProductionManager($this->resolvedJobRole());
    }

    public function hasProductionSupervisorJobRole(): bool
    {
        return FilamentJobRole::isProductionSupervisor($this->resolvedJobRole());
    }

    public function usesProductionSupervisorDashboard(): bool
    {
        return $this->hasRole(UserRole::ProductionSupervisor)
            || $this->hasProductionSupervisorJobRole();
    }

    public function isProductionManagerOnlyInFilament(): bool
    {
        return $this->hasProductionManagerJobRole()
            && ! $this->hasRole(UserRole::ProductionSupervisor);
    }

    public function canActAsProductionSupervisor(): bool
    {
        if ($this->isProductionManagerOnlyInFilament()) {
            return false;
        }

        return $this->hasProductionSupervisorJobRole()
            || $this->hasRole(UserRole::ProductionSupervisor);
    }

    public function isManagerUser(): bool
    {
        return $this->hasRole(UserRole::Manager)
            || $this->resolvedJobRole() === UserRole::Manager->label();
    }

    public function usesManagerDashboard(): bool
    {
        return $this->isManagerUser();
    }

    public function isAdminUser(): bool
    {
        return $this->resolvedJobRole() === 'Admin';
    }

    public function isDirectorUser(): bool
    {
        return $this->hasRole(UserRole::Director);
    }

    public function usesAdminDirectorDashboard(): bool
    {
        return $this->isDirectorUser() || $this->isAdminUser();
    }

    public function adminDirectorRoleLabel(): string
    {
        if ($this->isDirectorUser()) {
            return UserRole::Director->label();
        }

        if ($this->isAdminUser()) {
            return 'Admin';
        }

        return $this->resolvedJobRole() ?? UserRole::tryFromMixed($this->role)->label();
    }
}
