#!/usr/bin/env bash
# Fix Filament 403 after login: implement FilamentUser + normalize admin user records.
set -euo pipefail

BACKEND="/home/u630302848/paramgold-erp/backend"
cd "${BACKEND}"

echo "==> 1. Inspect admin/director users"
php artisan tinker --execute="
\$users = App\Models\User::query()
    ->where(function (\$q) {
        \$q->whereIn('email', ['admin@paramgold.in', 'admin@paramgroup.in'])
            ->orWhere('role', 'director')
            ->orWhere('job_role', 'Admin');
    })
    ->with('employee')
    ->get(['id','name','email','role','job_role','employee_id']);

foreach (\$users as \$u) {
    echo json_encode([
        'id' => \$u->id,
        'email' => \$u->email,
        'role' => \$u->role,
        'job_role' => \$u->job_role,
        'employee_id' => \$u->employee_id,
        'employee_status' => \$u->employee?->status,
        'employee_deleted' => \$u->employee?->deleted_at !== null,
        'canAccessPanel' => method_exists(\$u, 'canAccessPanel')
            ? \$u->canAccessPanel(filament()->getPanel('admin'))
            : null,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
"

echo "==> 2. Normalize primary admin accounts (preserve password hash)"
php artisan tinker --execute="
\$emails = ['admin@paramgold.in', 'admin@paramgroup.in'];
foreach (\$emails as \$email) {
    \$user = App\Models\User::query()->where('email', \$email)->first();
    if (! \$user) {
        echo \"skip missing: {\$email}\" . PHP_EOL;
        continue;
    }
    \$before = \$user->only(['role','job_role','employee_id']);
    \$updates = [];
    if (\$user->employee_id !== null) {
        \$updates['employee_id'] = null;
    }
    if ((\$user->role ?? '') !== 'employee' && ! \$user->hasRole(App\Enums\UserRole::Director)) {
        \$updates['role'] = 'employee';
    }
    if ((\$user->job_role ?? '') !== 'Admin') {
        \$updates['job_role'] = 'Admin';
    }
    if (\$updates !== []) {
        \$user->forceFill(\$updates)->save();
    }
    \$user->refresh();
    echo json_encode([
        'email' => \$email,
        'before' => \$before,
        'after' => \$user->only(['role','job_role','employee_id']),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
"

echo "==> 3. Ensure linked elevated users have active employees"
php artisan tinker --execute="
\$users = App\Models\User::query()
    ->whereNotNull('employee_id')
    ->whereIn('role', ['director','manager','production_supervisor'])
    ->with('employee')
    ->get();

foreach (\$users as \$user) {
    if (\$user->employee === null || \$user->employee->status !== true) {
        echo json_encode([
            'warning' => 'inactive_or_missing_employee',
            'user_id' => \$user->id,
            'email' => \$user->email,
            'role' => \$user->role,
            'employee_id' => \$user->employee_id,
            'employee_status' => \$user->employee?->status,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
"

echo "==> 4. Clear caches and sessions"
php artisan optimize:clear
rm -f storage/framework/sessions/*

echo "==> 5. Verify canAccessPanel for admin users"
php artisan tinker --execute="
\$panel = filament()->getPanel('admin');
foreach (['admin@paramgold.in', 'admin@paramgroup.in'] as \$email) {
    \$user = App\Models\User::query()->where('email', \$email)->first();
    if (! \$user) {
        continue;
    }
    echo \$email . ' => ' . ((\$user->canAccessPanel(\$panel)) ? 'allowed' : 'denied') . PHP_EOL;
}
"

echo "==> 6. HTTP checks"
curl -sI "https://erp.paramgold.in/admin" | head -10
curl -sI "https://erp.paramgold.in/admin/login" | head -10

echo "==> Done. Log in at https://erp.paramgold.in/admin/login"
