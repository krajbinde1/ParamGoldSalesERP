<?php

use App\Actions\Collections\UpdateCollectionStatus;
use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Filament\Pages\PaymentFollowUps;
use App\Jobs\SendWhatsAppOutboundMessage;
use App\Models\AppNotification;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerTallyLedger;
use App\Models\Employee;
use App\Models\PaymentFollowUpCycle;
use App\Models\PaymentFollowUpEntry;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Services\PaymentFollowUps\PaymentFollowUpCommitmentService;
use App\Services\PaymentFollowUps\PaymentFollowUpReminderService;
use App\Services\TallyLedger\TallyDealerLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function paymentFollowUpEmployee(string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Follow-up Employee '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => str_pad(substr($mobile, -12), 12, '4', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.substr($mobile, -4).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad($mobile, 12, '3', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee;
}

function paymentFollowUpDealer(Employee $employee, string $firmName, float $opening = 125000): Dealer
{
    $dealer = Dealer::query()->create([
        'firm_name' => $firmName,
        'owner_name' => 'Owner',
        'mobile' => '98'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
        'opening_balance' => $opening,
        'opening_balance_type' => 'debit',
        'opening_balance_date' => '2026-04-01',
    ]);

    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => $opening,
        'opening_balance_type' => 'debit',
        'opening_balance_explicit' => true,
        'financial_start_date' => '2026-04-01',
        'last_imported_at' => now(),
    ]);

    return $dealer;
}

function paymentFollowUpDirector(): User
{
    return User::query()->create([
        'name' => 'Follow-up Director',
        'email' => 'followup.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

function paymentFollowUpAdmin(): User
{
    return User::query()->create([
        'name' => 'Follow-up Admin',
        'email' => 'followup.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

beforeEach(function (): void {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lists only dealers assigned to the logged-in employee and reuses existing outstanding', function (): void {
    $employee = paymentFollowUpEmployee('9811300001');
    $other = paymentFollowUpEmployee('9811300002');
    $assigned = paymentFollowUpDealer($employee, 'ABC Fertilizers');
    paymentFollowUpDealer($other, 'Other Assigned Dealer');

    $response = $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/payment-follow-ups')
        ->assertOk();

    $names = collect($response->json('data'))->pluck('dealer_name')->all();

    expect($names)->toContain('ABC Fertilizers')
        ->and($names)->not->toContain('Other Assigned Dealer')
        ->and($response->json('data.0.current_outstanding'))->toBe(125000)
        ->and($response->json('data.0.status'))->toBe('no_follow_up')
        ->and($response->json('counts.no_follow_up'))->toBe(1);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/payment-follow-ups?search=ABC')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/payment-follow-ups?search=Missing')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($assigned))->toBe(125000.0);
});

it('saves follow-up history without overwriting and keeps the cycle open until outstanding is recovered', function (): void {
    $employee = paymentFollowUpEmployee('9811300003');
    $admin = paymentFollowUpAdmin();
    $dealer = paymentFollowUpDealer($employee, 'ABC Fertilizers');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Dealer requested 5 days',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-15',
        ])
        ->assertCreated()
        ->assertJsonPath('cycles.0.cycle_number', 1)
        ->assertJsonPath('cycles.0.status', 'open')
        ->assertJsonPath('cycles.0.opening_outstanding', 125000)
        ->assertJsonPath('cycles.0.entries.0.remark', 'Dealer requested 5 days');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Dealer requested another 3 days',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-18',
        ])
        ->assertCreated()
        ->assertJsonPath('cycles.0.entries.0.remark', 'Dealer requested 5 days')
        ->assertJsonPath('cycles.0.entries.1.remark', 'Dealer requested another 3 days')
        ->assertJsonCount(2, 'cycles.0.entries');

    expect(PaymentFollowUpCycle::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and(PaymentFollowUpEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);

    $collection = Collection::query()->create([
        'receipt_no' => 'RCP-PFU-1',
        'collection_date' => '2026-09-18',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 50000,
        'status' => Collection::STATUS_PENDING,
        'remarks' => 'Partial collection',
    ]);

    app(UpdateCollectionStatus::class)->execute($collection, Collection::STATUS_RECEIVED, $admin);

    $cycle = PaymentFollowUpCycle::query()->where('dealer_id', $dealer->id)->first();
    expect($cycle?->status)->toBe(PaymentFollowUpCycle::STATUS_OPEN)
        ->and((float) $cycle->payment_received_amount)->toBe(50000.0)
        ->and($cycle->closed_at)->toBeNull()
        ->and(PaymentFollowUpEntry::query()->where('cycle_id', $cycle->id)->count())->toBe(3)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(75000.0);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Continue remaining outstanding follow-up',
            'expected_amount' => 40000,
            'next_follow_up_date' => '2026-09-25',
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'cycles')
        ->assertJsonPath('cycles.0.cycle_number', 1)
        ->assertJsonPath('cycles.0.status', 'open')
        ->assertJsonPath('cycles.0.entries.0.remark', 'Dealer requested 5 days')
        ->assertJsonPath('cycles.0.entries.2.entry_type', 'payment_received')
        ->assertJsonPath('cycles.0.entries.3.remark', 'Continue remaining outstanding follow-up');

    $closing = Collection::query()->create([
        'receipt_no' => 'RCP-PFU-2',
        'collection_date' => '2026-09-25',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 75000,
        'status' => Collection::STATUS_PENDING,
        'remarks' => 'Remaining collection',
    ]);

    app(UpdateCollectionStatus::class)->execute($closing, Collection::STATUS_RECEIVED, $admin);

    $cycle->refresh();
    expect($cycle->status)->toBe(PaymentFollowUpCycle::STATUS_CLOSED)
        ->and((float) $cycle->payment_received_amount)->toBe(125000.0)
        ->and((float) $cycle->closing_outstanding)->toBe(0.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(0.0);
});

it('marks a missed commitment without closing the cycle and continues follow-ups in the same cycle', function (): void {
    $employee = paymentFollowUpEmployee('9811300011');
    $dealer = paymentFollowUpDealer($employee, 'Missed Commitment Dealer');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Promised by 15 Sep',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-15',
        ])
        ->assertCreated();

    Carbon::setTestNow(Carbon::parse('2026-09-16 11:00:00', 'Asia/Kolkata'));

    $overdue = app(\App\Services\PaymentFollowUps\PaymentFollowUpService::class)->dealerDetail($dealer->fresh());

    expect($overdue['cycles'])->toHaveCount(1)
        ->and($overdue['cycles'][0]['status'])->toBe('open')
        ->and($overdue['cycles'][0]['display_status'])->toBe('overdue')
        ->and($overdue['cycles'][0]['status_label'])->toBe('OVERDUE')
        ->and($overdue['cycles'][0]['entries'][0]['commitment_status'])->toBe('missed')
        ->and($overdue['cycles'][0]['missed_commitment_count'])->toBe(1);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Follow-up after missed date',
            'expected_amount' => 40000,
            'next_follow_up_date' => '2026-09-20',
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'cycles')
        ->assertJsonPath('cycles.0.cycle_number', 1)
        ->assertJsonPath('cycles.0.status', 'open')
        ->assertJsonPath('cycles.0.display_status', 'open')
        ->assertJsonPath('cycles.0.entries.0.commitment_status', 'missed')
        ->assertJsonPath('cycles.0.entries.1.commitment_status', 'pending')
        ->assertJsonPath('cycles.0.follow_up_count', 2);
});

it('does not list an unassigned dealer and does not change outstanding when adding a follow-up', function (): void {
    $employee = paymentFollowUpEmployee('9811300004');
    $other = paymentFollowUpEmployee('9811300005');
    $foreign = paymentFollowUpDealer($other, 'Not Mine');

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/payment-follow-ups/'.$foreign->id)
        ->assertUnprocessable();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$foreign->id, [
            'remark' => 'Should not save',
            'next_follow_up_date' => '2026-09-15',
        ])
        ->assertUnprocessable();

    expect(PaymentFollowUpCycle::query()->count())->toBe(0);
});

it('sends an employee reminder once and skips WhatsApp until the payment_reminder template is configured', function (): void {
    $employee = paymentFollowUpEmployee('9811300006');
    $dealer = paymentFollowUpDealer($employee, 'ABC Fertilizers');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Dealer committed payment after market collection.',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-09',
        ])
        ->assertCreated();

    $entry = PaymentFollowUpEntry::query()->first();
    expect($entry)->not->toBeNull();

    $stats = app(PaymentFollowUpReminderService::class)->sendDueReminders();
    expect($stats['employee_sent'])->toBe(1)
        ->and($stats['whatsapp_sent'])->toBe(0);

    $entry->refresh();
    expect($entry->employee_notification_status)->toBe(PaymentFollowUpEntry::REMINDER_SENT)
        ->and($entry->whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_SKIPPED)
        ->and($entry->commitment_whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_SKIPPED)
        ->and(AppNotification::query()->where('type', 'payment_follow_up_due')->count())->toBe(1);

    $again = app(PaymentFollowUpReminderService::class)->sendDueReminders();
    expect($again['employee_sent'])->toBe(0)
        ->and(AppNotification::query()->where('type', 'payment_follow_up_due')->count())->toBe(1);
});

it('queues a payment_commitment whatsapp immediately when a follow-up is saved and does not duplicate the same entry', function (): void {
    config()->set('services.whatsapp.payment_commitment_template', 'payment_commitment');

    $employee = paymentFollowUpEmployee('9811300008');
    $dealer = paymentFollowUpDealer($employee, 'ABC Fertilizers');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Dealer promised payment next week.',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-15',
        ])
        ->assertCreated();

    $entry = PaymentFollowUpEntry::query()->first();
    expect($entry)->not->toBeNull()
        ->and($entry->commitment_whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_PENDING)
        ->and($entry->whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_PENDING);

    $message = WhatsAppOutboundMessage::query()
        ->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_COMMITMENT)
        ->where('source_id', $entry->id)
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->erp_reference)->toBe('WA-PFU-C-'.$entry->id)
        ->and($message->to_number)->toBe('+91'.$dealer->mobile)
        ->and($message->payload['type'] ?? null)->toBe('payment_commitment')
        ->and($message->payload['promised_amount'] ?? null)->toEqual(50000)
        ->and($message->payload['promised_date'] ?? null)->toBe('2026-09-15')
        ->and($message->payload['outstanding'] ?? null)->toEqual(125000)
        ->and($message->payload['body'] ?? '')->toContain('Thank you for your payment commitment.')
        ->and($entry->commitment_whatsapp_outbound_message_id)->toBe($message->id);

    Queue::assertPushed(SendWhatsAppOutboundMessage::class, fn (SendWhatsAppOutboundMessage $job): bool => $job->messageId === $message->id);

    app(PaymentFollowUpCommitmentService::class)->sendForEntry($entry->fresh());

    expect(WhatsAppOutboundMessage::query()->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_COMMITMENT)->count())->toBe(1)
        ->and(WhatsAppOutboundMessage::query()->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_FOLLOWUP)->count())->toBe(0);
});

it('queues a new payment_commitment when Follow-up Again is saved and retries a failed commitment', function (): void {
    config()->set('services.whatsapp.payment_commitment_template', 'payment_commitment');

    $employee = paymentFollowUpEmployee('9811300009');
    $dealer = paymentFollowUpDealer($employee, 'ABC Fertilizers');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'First promise',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-15',
        ])
        ->assertCreated();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Follow-up Again with a new date',
            'expected_amount' => 40000,
            'next_follow_up_date' => '2026-09-18',
        ])
        ->assertCreated();

    $entries = PaymentFollowUpEntry::query()->orderBy('id')->get();
    expect($entries)->toHaveCount(2);

    $messages = WhatsAppOutboundMessage::query()
        ->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_COMMITMENT)
        ->orderBy('id')
        ->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->source_id)->toBe($entries[0]->id)
        ->and($messages[1]->source_id)->toBe($entries[1]->id)
        ->and($messages[1]->payload['promised_amount'] ?? null)->toEqual(40000)
        ->and($messages[1]->payload['promised_date'] ?? null)->toBe('2026-09-18')
        ->and($entries[0]->fresh()->whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_PENDING)
        ->and($entries[1]->fresh()->whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_PENDING);

    $first = $messages[0];
    $first->update([
        'status' => WhatsAppOutboundMessage::STATUS_FAILED,
        'error' => 'Temporary WhatsApp failure',
    ]);
    $entries[0]->forceFill([
        'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_FAILED,
        'commitment_whatsapp_error' => 'Temporary WhatsApp failure',
    ])->save();

    $retried = app(PaymentFollowUpCommitmentService::class)->retryUnsent();

    expect($retried['failed'])->toBe(0)
        ->and(WhatsAppOutboundMessage::query()->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_COMMITMENT)->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(WhatsAppOutboundMessage::STATUS_PENDING)
        ->and($entries[0]->fresh()->commitment_whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_PENDING);
});

it('lets admin view the assigned dealer follow-up timeline', function (): void {
    $admin = paymentFollowUpAdmin();
    $employee = paymentFollowUpEmployee('9811300007');
    $dealer = paymentFollowUpDealer($employee, 'ABC Fertilizers');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Called dealer',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-15',
        ])
        ->assertCreated();

    Livewire::actingAs($admin)
        ->test(PaymentFollowUps::class)
        ->assertOk()
        ->assertSee('ABC Fertilizers')
        ->assertSee($employee->full_name);

    Livewire::actingAs($admin)
        ->test(PaymentFollowUps::class, ['dealerId' => $dealer->id])
        ->assertOk()
        ->assertSee('Called dealer')
        ->assertSee('Payment Cycle #1')
        ->assertSee('Current Due')
        ->assertSee('Follow-up #1')
        ->assertSee('PENDING')
        ->assertDontSee('Opening Due')
        ->assertDontSee('Opening Outstanding');
});

it('lets the director list assigned dealers after selecting an employee and view read-only history', function (): void {
    $director = paymentFollowUpDirector();
    $employee = paymentFollowUpEmployee('9811300091');
    $other = paymentFollowUpEmployee('9811300092');
    $assigned = paymentFollowUpDealer($employee, 'Director Follow Dealer');
    paymentFollowUpDealer($other, 'Other Employee Dealer');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$assigned->id, [
            'remark' => 'Promised next week',
            'expected_amount' => 40000,
            'next_follow_up_date' => '2026-09-16',
        ])
        ->assertCreated();

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-follow-ups')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('counts.overdue', 0);

    $list = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-follow-ups?employee_id='.$employee->id)
        ->assertOk();

    $names = collect($list->json('data'))->pluck('dealer_name')->all();

    expect($names)->toContain('Director Follow Dealer')
        ->and($names)->not->toContain('Other Employee Dealer')
        ->and($list->json('data.0.status'))->toBe('upcoming')
        ->and($list->json('data.0.next_follow_up_date'))->toBe('2026-09-16');

    $history = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-follow-ups/'.$assigned->id)
        ->assertOk();

    expect($history->json('can_add_follow_up'))->toBeFalse()
        ->and($history->json('cycles.0.entries.0.remark'))->toBe('Promised next week')
        ->and((float) $history->json('cycles.0.entries.0.expected_amount'))->toBe(40000.0)
        ->and($history->json('cycles.0.entries.0.employee_name'))->toBe($employee->full_name);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/director/payment-follow-ups?employee_id='.$employee->id)
        ->assertForbidden();

    $blocked = $this->actingAs($director, 'sanctum')
        ->postJson('/api/director/payment-follow-ups/'.$assigned->id, [
            'remark' => 'Director must not save',
            'next_follow_up_date' => '2026-09-20',
        ]);

    expect($blocked->status())->toBeIn([404, 405]);
});
