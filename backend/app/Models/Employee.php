<?php

namespace App\Models;

use App\Actions\Employees\ProvisionEmployeeUserAccount;
use App\Enums\UserRole;
use App\Models\Concerns\EnforcesSafeDelete;
use App\Support\EmployeeCodeGenerator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class Employee extends Authenticatable
{
    use EnforcesSafeDelete;
    use HasApiTokens, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Employee $employee): void {
            if (filled($employee->employee_code)) {
                return;
            }

            $employee->employee_code = EmployeeCodeGenerator::generateNext();
        });

        static::updating(function (Employee $employee): void {
            if (
                $employee->isDirty('employee_code')
                && filled($employee->getOriginal('employee_code'))
            ) {
                $employee->employee_code = $employee->getOriginal('employee_code');
            }
        });

        static::saving(function (Employee $employee): void {
            if ($employee->travel_allowance_type === 'per_km') {
                $employee->company_card_issued = false;
                $employee->monthly_travel_expense_limit = null;
                $employee->company_card_last_four = null;
            }

            if ($employee->travel_allowance_type === 'actual_expense') {
                $employee->rate_per_km = null;
                $employee->daily_km_limit = null;
                $employee->monthly_km_limit = null;

                if (! $employee->company_card_issued) {
                    $employee->company_card_last_four = null;
                }
            }
        });

        static::restored(function (Employee $employee): void {
            if (! $employee->user()->exists()) {
                app(ProvisionEmployeeUserAccount::class)->execute($employee);
            }
        });
    }

    public static function uniqueAmongActive(string $column, ?int $ignoreId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('employees', $column)->whereNull('deleted_at');

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }

        return $rule;
    }

    public static function uniqueAmongActiveUsers(string $column, ?int $ignoreUserId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('users', $column)->where(function ($query): void {
            $query->whereExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('employees')
                    ->whereColumn('employees.id', 'users.employee_id')
                    ->whereNull('employees.deleted_at');
            });
        });

        if ($ignoreUserId !== null) {
            $rule->ignore($ignoreUserId);
        }

        return $rule;
    }

    public static function loginIdRules(?int $ignoreUserId = null): array
    {
        return [
            'required',
            'string',
            'min:4',
            'max:32',
            'regex:/^[A-Za-z0-9]+$/',
            Rule::unique('users', 'login_id')->ignore($ignoreUserId),
        ];
    }

    protected $fillable = [
        'full_name',
        'mobile',
        'email',
        'department',
        'designation',
        'reporting_manager_id',
        'joining_date',
        'salary',
        'base_location',
        'daily_allowance',
        'travel_allowance',
        'travel_allowance_type',
        'rate_per_km',
        'daily_km_limit',
        'monthly_km_limit',
        'company_card_issued',
        'monthly_travel_expense_limit',
        'company_card_last_four',
        'aadhaar_number',
        'pan_number',
        'bank_name',
        'account_number',
        'ifsc_code',
        'profile_photo_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'salary' => 'decimal:2',
            'daily_allowance' => 'decimal:2',
            'travel_allowance' => 'decimal:2',
            'rate_per_km' => 'decimal:2',
            'daily_km_limit' => 'decimal:2',
            'monthly_km_limit' => 'decimal:2',
            'company_card_issued' => 'boolean',
            'monthly_travel_expense_limit' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reporting_manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'reporting_manager_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function routePoints(): HasMany
    {
        return $this->hasMany(EmployeeRoutePoint::class);
    }

    public function assignedDealers(): HasMany
    {
        return $this->hasMany(Dealer::class, 'assigned_employee_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EmployeeTask::class);
    }

    public function assignedDealersCount(): int
    {
        return $this->assignedDealers()->count();
    }

    public function displayLabel(): string
    {
        if (filled($this->employee_code)) {
            return "{$this->employee_code} — {$this->full_name}";
        }

        return $this->full_name;
    }

    public function assignmentLabel(): string
    {
        if (filled($this->employee_code)) {
            return "{$this->employee_code} - {$this->full_name}";
        }

        return $this->full_name;
    }

    public static function resolveByCode(?string $employeeCode): ?self
    {
        $code = trim((string) $employeeCode);

        if ($code === '') {
            return null;
        }

        return static::query()
            ->whereRaw('UPPER(employee_code) = ?', [strtoupper($code)])
            ->first();
    }

    public static function resolveActiveByCode(?string $employeeCode): ?self
    {
        $employee = static::resolveByCode($employeeCode);

        if ($employee === null || ! $employee->status || $employee->trashed()) {
            return null;
        }

        return $employee;
    }

    public static function accountAndTravelRules(?self $employee = null): array
    {
        return [
            'mobile' => [
                'required',
                'string',
                'digits:10',
                'regex:/^[6-9][0-9]{9}$/',
                static::uniqueAmongActive('mobile', $employee?->id),
                static::uniqueAmongActiveUsers('login_id', $employee?->user?->id),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                static::uniqueAmongActive('email', $employee?->id),
                static::uniqueAmongActiveUsers('email', $employee?->user?->id),
            ],
            'travel_allowance_type' => ['required', Rule::in(['per_km', 'actual_expense'])],
            'rate_per_km' => ['exclude_unless:travel_allowance_type,per_km', 'required', 'numeric', 'min:0'],
            'daily_km_limit' => ['exclude_unless:travel_allowance_type,per_km', 'required', 'numeric', 'min:0'],
            'monthly_km_limit' => [
                'exclude_unless:travel_allowance_type,per_km',
                'required',
                'numeric',
                'min:0',
            ],
            'company_card_issued' => ['boolean'],
            'monthly_travel_expense_limit' => [
                'exclude_unless:travel_allowance_type,actual_expense',
                'required',
                'numeric',
                'min:0',
            ],
            'company_card_last_four' => [
                'exclude_unless:travel_allowance_type,actual_expense',
                'nullable',
                'string',
                'max:4',
            ],
        ];
    }

    public static function creationRules(?self $employee = null): array
    {
        return array_merge([
            'full_name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'designation' => ['required', 'string', 'max:255'],
            'joining_date' => ['required', 'date'],
            'base_location' => ['required', 'string', 'max:255'],
            'salary' => ['required', 'numeric', 'min:0'],
            'daily_allowance' => ['required', 'numeric', 'min:0'],
            'aadhaar_number' => [
                'required',
                'string',
                'digits:12',
                'regex:/^[2-9][0-9]{11}$/',
                static::uniqueAmongActive('aadhaar_number', $employee?->id),
            ],
            'pan_number' => [
                'required',
                'string',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
                static::uniqueAmongActive('pan_number', $employee?->id),
            ],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:30'],
            'ifsc_code' => ['required', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'profile_photo_path' => ['nullable', 'string', 'max:255'],
            'reporting_manager_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['boolean'],
            'role' => ['required', 'string', Rule::in(array_column(UserRole::cases(), 'value'))],
        ], static::accountAndTravelRules($employee));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeCreationData(array $data): array
    {
        if (filled($data['pan_number'] ?? null)) {
            $data['pan_number'] = strtoupper(trim((string) $data['pan_number']));
        }

        if (filled($data['ifsc_code'] ?? null)) {
            $data['ifsc_code'] = strtoupper(trim((string) $data['ifsc_code']));
        }

        if (filled($data['aadhaar_number'] ?? null)) {
            $data['aadhaar_number'] = preg_replace('/\D/', '', (string) $data['aadhaar_number']);
        }

        if (array_key_exists('account_number', $data) && filled($data['account_number'])) {
            $data['account_number'] = trim((string) $data['account_number']);
        }

        if (filled($data['company_card_last_four'] ?? null)) {
            $raw = trim((string) $data['company_card_last_four']);

            if (preg_match('/^\d{1,4}$/', $raw)) {
                $data['company_card_last_four'] = str_pad($raw, 4, '0', STR_PAD_LEFT);
            } else {
                $data['company_card_last_four'] = $raw;
            }
        }

        if (array_key_exists('company_card_issued', $data)) {
            $data['company_card_issued'] = filter_var(
                $data['company_card_issued'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? false;
        }

        if (blank($data['role'] ?? null)) {
            $data['role'] = UserRole::Employee->value;
        }

        return $data;
    }

    public static function validateCompanyCard(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $data = $validator->getData();

            if (($data['travel_allowance_type'] ?? '') !== 'actual_expense') {
                return;
            }

            $issued = filter_var($data['company_card_issued'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $lastFour = trim((string) ($data['company_card_last_four'] ?? ''));

            if ($issued && ! preg_match('/^\d{4}$/', $lastFour)) {
                $validator->errors()->add(
                    'company_card_last_four',
                    'Enter exactly four numeric digits for the company card.',
                );
            }
        });
    }

    public static function validateReportingManager(
        \Illuminate\Validation\Validator $validator,
        ?self $employee = null,
    ): void {
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($employee): void {
            $managerId = $validator->getData()['reporting_manager_id'] ?? null;

            if ($managerId === null || $employee === null) {
                return;
            }

            if ((int) $managerId === (int) $employee->id) {
                $validator->errors()->add(
                    'reporting_manager_id',
                    'An employee cannot be their own reporting manager.',
                );
            }
        });
    }

    public static function mapQueryExceptionToValidation(QueryException $exception): ValidationException
    {
        $message = $exception->getMessage();

        $fieldMessages = [
            'mobile' => 'This mobile number is already assigned to another employee or login account.',
            'email' => 'This email is already assigned to another employee or login account.',
            'aadhaar_number' => 'This Aadhaar number is already assigned to another employee.',
            'pan_number' => 'This PAN is already assigned to another employee.',
            'employee_code' => 'This employee code is already in use.',
        ];

        $columnMap = [
            'employees.mobile' => 'mobile',
            'employees.email' => 'email',
            'employees.aadhaar_number' => 'aadhaar_number',
            'employees.pan_number' => 'pan_number',
            'employees.employee_code' => 'employee_code',
            'users.login_id' => 'mobile',
            'users.email' => 'email',
        ];

        foreach ($columnMap as $column => $field) {
            if (str_contains($message, $column)) {
                return ValidationException::withMessages([
                    $field => $fieldMessages[$field] ?? 'This value is already in use.',
                ]);
            }
        }

        throw $exception;
    }

    public static function validateTravelKmLimits(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $data = $validator->getData();

            if (($data['travel_allowance_type'] ?? '') !== 'per_km') {
                return;
            }

            $daily = (float) ($data['daily_km_limit'] ?? 0);
            $monthly = (float) ($data['monthly_km_limit'] ?? 0);

            if ($monthly < $daily) {
                $validator->errors()->add(
                    'monthly_km_limit',
                    'The monthly KM limit must be greater than or equal to daily km limit.',
                );
            }
        });
    }
}
