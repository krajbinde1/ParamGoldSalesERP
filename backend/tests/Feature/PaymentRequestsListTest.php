<?php

use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Widgets\AdminDirectorPaymentOverviewWidget;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\Dashboard\DirectorDashboardDataService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function paymentListAdmin(): User
{
    return User::query()->create([
        'name' => 'Payment List Admin',
        'email' => 'pay.list.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function paymentListRequest(User $admin, string $status, string $vendor, ?string $requestNo = null, float $amount = 10000): PaymentRequest
{
    return PaymentRequest::query()->create([
        'request_no' => $requestNo ?? 'PR-'.uniqid(),
        'vendor_name' => $vendor,
        'vendor_mobile' => '98765'.random_int(10000, 99999),
        'amount' => $amount,
        'remark' => 'Test',
        'status' => $status,
        'created_by' => $admin->id,
        'reminder_count' => 0,
    ]);
}

it('shows payment approval summary cards and lists pending requests first', function (): void {
    $admin = paymentListAdmin();
    $done = paymentListRequest($admin, PaymentRequest::STATUS_PAYMENT_DONE, 'Done Vendor');
    $rejected = paymentListRequest($admin, PaymentRequest::STATUS_REJECTED_FIRST, 'Rejected Vendor');
    $pending = paymentListRequest($admin, PaymentRequest::STATUS_PENDING_FIRST, 'Pending Vendor');

    $page = Livewire::actingAs($admin)
        ->test(ListPaymentRequests::class)
        ->assertSuccessful()
        ->assertSeeLivewire(AdminDirectorPaymentOverviewWidget::class)
        ->assertCanSeeTableRecords([$pending, $done, $rejected]);

    Livewire::actingAs($admin)
        ->test(AdminDirectorPaymentOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Pending My Approval')
        ->assertSee('Pending Next Approval')
        ->assertSee('Payment Done Today');

    $ids = $page->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

    expect($ids[0])->toBe($pending->id)
        ->and($ids)->toContain($done->id)
        ->and($ids)->toContain($rejected->id);
});

it('counts pending my approval only when the logged-in user is the current approver', function (): void {
    Cache::flush();
    app()->forgetInstance(DirectorDashboardDataService::class);

    User::query()->create([
        'name' => 'Krishna Rajbinde',
        'email' => 'krishna.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
    $krishna = User::query()->create([
        'name' => 'Krishna Rajbinde',
        'email' => 'krishna.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
    User::query()->create([
        'name' => 'Bhagwan Kakde',
        'email' => 'bhagwan.pay.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);

    $mine = paymentListRequest(
        $krishna,
        PaymentRequest::STATUS_PENDING_FIRST,
        'Krishna Vendor',
        'PR-0003',
        15000,
    );
    $notMine = paymentListRequest(
        $krishna,
        PaymentRequest::STATUS_PENDING_SECOND,
        'Bhagwan Vendor',
        'PR-0005',
        25000,
    );

    $payments = app(DirectorDashboardDataService::class)->snapshot($krishna)['payments'];

    expect($payments['my_pending_count'])->toBe(1)
        ->and((float) $payments['my_pending_amount'])->toBe(15000.0)
        ->and($payments['my_filter'])->toBe('pending_my_approval');

    $page = Livewire::actingAs($krishna)
        ->test(ListPaymentRequests::class)
        ->filterTable('workflow_status', $payments['my_filter'])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$notMine]);

    $ids = $page->instance()->getFilteredTableQuery()->pluck('id')->all();

    expect($ids)->toBe([$mine->id]);
});
