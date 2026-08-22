<?php

use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Widgets\AdminDirectorPaymentOverviewWidget;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Enums\UserRole;
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

function paymentListRequest(User $admin, string $status, string $vendor): PaymentRequest
{
    return PaymentRequest::query()->create([
        'request_no' => 'PR-'.uniqid(),
        'vendor_name' => $vendor,
        'vendor_mobile' => '98765'.random_int(10000, 99999),
        'amount' => 10000,
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
