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
        ->assertJsonPath('cycles.0.entries.0.remark', 'Dealer requested 5 days')
        ->assertJsonPath('can_add_follow_up', false)
        ->assertJsonPath('next_follow_up_available_on', '2026-09-15')
        ->assertJsonPath('next_follow_up_available_message', 'Next follow-up available on 15 Sep 2026.');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Dealer requested another 3 days',
            'expected_amount' => 50000,
            'next_follow_up_date' => '2026-09-18',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.next_follow_up_date.0', 'Next follow-up available on 15 Sep 2026.');

    Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', 'Asia/Kolkata'));

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

    Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'Asia/Kolkata'));

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

it('classifies overdue dealers with multiple missed commitments as high risk for director monitoring', function (): void {
    $director = paymentFollowUpDirector();
    $employee = paymentFollowUpEmployee('9811300015');
    $dealer = paymentFollowUpDealer($employee, 'High Risk Dealer', 200000);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'First promise',
            'expected_amount' => 80000,
            'next_follow_up_date' => '2026-09-10',
        ])
        ->assertCreated();

    Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00', 'Asia/Kolkata'));

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Second promise',
            'expected_amount' => 70000,
            'next_follow_up_date' => '2026-09-12',
        ])
        ->assertCreated();

    Carbon::setTestNow(Carbon::parse('2026-09-13 10:00:00', 'Asia/Kolkata'));

    $monitor = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-follow-ups?employee_id='.$employee->id)
        ->assertOk();

    expect($monitor->json('data.0.dealer_name'))->toBe('High Risk Dealer')
        ->and($monitor->json('data.0.display_status'))->toBe('overdue')
        ->and($monitor->json('data.0.missed_count'))->toBe(2)
        ->and($monitor->json('data.0.risk'))->toBe('high')
        ->and($monitor->json('data.0.risk_label'))->toBe('HIGH RISK')
        ->and((float) $monitor->json('data.0.total_committed'))->toBe(150000.0)
        ->and($monitor->json('summary.overdue_dealers'))->toBe(1)
        ->and($monitor->json('today_actions.overdue.0.dealer_name'))->toBe('High Risk Dealer')
        ->and($monitor->json('employee_performance.0.missed_commitments'))->toBe(2);

    $dashboard = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/dashboard')
        ->assertOk();

    expect((int) $dashboard->json('company_summary.payment_follow_up.overdue'))->toBe(1)
        ->and((int) $dashboard->json('company_summary.payment_follow_up.due_today'))->toBe(0)
        ->and((int) $dashboard->json('company_summary.payment_follow_up.attention'))->toBe(1)
        ->and((int) $dashboard->json('company_summary.payment_follow_up.action_required'))->toBe(1)
        ->and((int) $dashboard->json('company_summary.payment_follow_up.requires_follow_up'))->toBeGreaterThanOrEqual(1);
});

it('counts director dashboard action required as unique overdue plus due-today dealers only', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'Asia/Kolkata'));

    $director = paymentFollowUpDirector();
    $employee = paymentFollowUpEmployee('9811300021');
    $overdueDealer = paymentFollowUpDealer($employee, 'Overdue Action Dealer');
    $dueTodayDealer = paymentFollowUpDealer($employee, 'Due Today Action Dealer');
    $upcomingDealer = paymentFollowUpDealer($employee, 'Upcoming Action Dealer');
    paymentFollowUpDealer($employee, 'No Follow-up Action Dealer');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$overdueDealer->id, [
            'remark' => 'Missed commitment',
            'expected_amount' => 20000,
            'next_follow_up_date' => '2026-09-08',
        ])
        ->assertCreated();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dueTodayDealer->id, [
            'remark' => 'Due today',
            'expected_amount' => 15000,
            'next_follow_up_date' => '2026-09-10',
        ])
        ->assertCreated();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$upcomingDealer->id, [
            'remark' => 'Later this week',
            'expected_amount' => 10000,
            'next_follow_up_date' => '2026-09-18',
        ])
        ->assertCreated();

    Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Asia/Kolkata'));

    $dashboard = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/dashboard')
        ->assertOk();

    $followUp = $dashboard->json('company_summary.payment_follow_up');

    expect((int) $followUp['overdue'])->toBe(1)
        ->and((int) $followUp['due_today'])->toBe(1)
        ->and((int) $followUp['upcoming'])->toBe(1)
        ->and((int) $followUp['no_follow_up'])->toBeGreaterThanOrEqual(1)
        ->and((int) $followUp['action_required'])->toBe(2)
        ->and((int) $followUp['attention'])->toBe(2)
        ->and((int) $followUp['requires_follow_up'])->toBeGreaterThan(2);
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

it('sends WhatsApp payment reminder at 9am on the commitment date and overdue on the next run', function (): void {
    config()->set('services.whatsapp.payment_reminder_template', 'payment_reminder');

    $employee = paymentFollowUpEmployee('9811300016');
    $dealer = paymentFollowUpDealer($employee, 'Reminder Time Dealer');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dealer->id, [
            'remark' => 'Promised today',
            'expected_amount' => 25000,
            'next_follow_up_date' => '2026-09-09',
        ])
        ->assertCreated();

    $entry = PaymentFollowUpEntry::query()->first();
    expect($entry)->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-09-09 08:59:00', 'Asia/Kolkata'));
    $beforeNine = app(PaymentFollowUpReminderService::class)->sendDueReminders();
    expect($beforeNine['whatsapp_sent'])->toBe(0)
        ->and($entry->fresh()->whatsapp_status)->toBe(PaymentFollowUpEntry::REMINDER_PENDING)
        ->and(WhatsAppOutboundMessage::query()->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_FOLLOWUP)->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-09-09 09:00:00', 'Asia/Kolkata'));
    app(PaymentFollowUpReminderService::class)->sendDueReminders();
    expect(WhatsAppOutboundMessage::query()->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_FOLLOWUP)->count())->toBe(1);

    app(PaymentFollowUpReminderService::class)->sendDueReminders();
    expect(WhatsAppOutboundMessage::query()->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_FOLLOWUP)->count())->toBe(1);

    $overdueEmployee = paymentFollowUpEmployee('9811300017');
    $overdueDealer = paymentFollowUpDealer($overdueEmployee, 'Overdue Reminder Dealer');
    $this->actingAs($overdueEmployee->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$overdueDealer->id, [
            'remark' => 'Promised yesterday',
            'expected_amount' => 15000,
            'next_follow_up_date' => '2026-09-10',
        ])
        ->assertCreated();

    Carbon::setTestNow(Carbon::parse('2026-09-11 08:10:00', 'Asia/Kolkata'));
    app(PaymentFollowUpReminderService::class)->sendDueReminders();
    expect(WhatsAppOutboundMessage::query()
        ->where('source_type', WhatsAppOutboundMessage::SOURCE_PAYMENT_FOLLOWUP)
        ->count())->toBe(2);
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

    Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', 'Asia/Kolkata'));

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

    $all = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-follow-ups')
        ->assertOk();

    $allNames = collect($all->json('data'))->pluck('dealer_name')->all();

    expect($allNames)->toContain('Director Follow Dealer')
        ->and($allNames)->toContain('Other Employee Dealer')
        ->and($all->json('summary.overdue_dealers'))->toBe(0)
        ->and($all->json('counts.overdue'))->toBe(0)
        ->and($all->json('employee_performance'))->not->toBeEmpty();

    $list = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-follow-ups?employee_id='.$employee->id)
        ->assertOk();

    $names = collect($list->json('data'))->pluck('dealer_name')->all();

    expect($names)->toContain('Director Follow Dealer')
        ->and($names)->not->toContain('Other Employee Dealer')
        ->and($list->json('data.0.status'))->toBe('upcoming')
        ->and($list->json('data.0.display_status'))->toBe('pending')
        ->and($list->json('data.0.display_status_label'))->toBe('PENDING')
        ->and($list->json('data.0.next_follow_up_date'))->toBe('2026-09-16')
        ->and($list->json('data.0.follow_up_count'))->toBe(1)
        ->and($list->json('data.0.risk'))->toBe('low')
        ->and($list->json('summary.commitments_due_today'))->toBe(0);

    $history = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-follow-ups/'.$assigned->id)
        ->assertOk();

    expect($history->json('can_add_follow_up'))->toBeFalse()
        ->and($history->json('display_status_label'))->toBe('PENDING')
        ->and($history->json('follow_up_count'))->toBe(1)
        ->and($history->json('cycles.0.entries.0.remark'))->toBe('Promised next week')
        ->and((float) $history->json('cycles.0.entries.0.expected_amount'))->toBe(40000.0)
        ->and($history->json('cycles.0.entries.0.employee_name'))->toBe($employee->full_name);

    $dashboard = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/dashboard')
        ->assertOk();

    expect((int) $dashboard->json('company_summary.payment_follow_up.no_follow_up'))->toBeGreaterThanOrEqual(1)
        ->and((int) $dashboard->json('company_summary.payment_follow_up.action_required'))->toBe(0)
        ->and((int) $dashboard->json('company_summary.payment_follow_up.requires_follow_up'))->toBeGreaterThanOrEqual(1);

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

function paymentFollowUpManager(string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Follow-up Manager '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Manager',
        'joining_date' => '2026-01-01',
        'salary' => 45000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => str_pad(substr($mobile, -12), 12, '5', STR_PAD_LEFT),
        'pan_number' => 'MNGDE'.substr($mobile, -4).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad($mobile, 12, '7', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Manager->value,
    ])->employee;
}

it('scopes manager payment recovery and dashboard action required to direct-report dealers only', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'Asia/Kolkata'));

    $manager = paymentFollowUpManager('9811300301');
    $otherManager = paymentFollowUpManager('9811300302');
    $report = paymentFollowUpEmployee('9811300303');
    $foreignReport = paymentFollowUpEmployee('9811300304');
    $report->update(['reporting_manager_id' => $manager->id]);
    $foreignReport->update(['reporting_manager_id' => $otherManager->id]);

    $overdueDealer = paymentFollowUpDealer($report, 'Manager Overdue Dealer');
    $dueTodayDealer = paymentFollowUpDealer($report, 'Manager Due Today Dealer');
    $upcomingDealer = paymentFollowUpDealer($report, 'Manager Upcoming Dealer');
    $noFollowUpDealer = paymentFollowUpDealer($report, 'Manager No Follow-up Dealer');
    $foreignOverdue = paymentFollowUpDealer($foreignReport, 'Other Manager Overdue Dealer');

    $this->actingAs($report->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$overdueDealer->id, [
            'remark' => 'Missed commitment',
            'expected_amount' => 20000,
            'next_follow_up_date' => '2026-09-08',
        ])
        ->assertCreated();

    $this->actingAs($report->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$dueTodayDealer->id, [
            'remark' => 'Due today',
            'expected_amount' => 15000,
            'next_follow_up_date' => '2026-09-10',
        ])
        ->assertCreated();

    $this->actingAs($report->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$upcomingDealer->id, [
            'remark' => 'Later this week',
            'expected_amount' => 10000,
            'next_follow_up_date' => '2026-09-18',
        ])
        ->assertCreated();

    $this->actingAs($foreignReport->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$foreignOverdue->id, [
            'remark' => 'Other team missed',
            'expected_amount' => 18000,
            'next_follow_up_date' => '2026-09-08',
        ])
        ->assertCreated();

    Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Asia/Kolkata'));

    $dashboard = $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/dashboard')
        ->assertOk();

    $followUp = $dashboard->json('payment_follow_up');

    expect((int) $followUp['overdue'])->toBe(1)
        ->and((int) $followUp['due_today'])->toBe(1)
        ->and((int) $followUp['upcoming'])->toBe(1)
        ->and((int) $followUp['no_follow_up'])->toBeGreaterThanOrEqual(1)
        ->and((int) $followUp['action_required'])->toBe(2)
        ->and((int) $followUp['attention'])->toBe(2)
        ->and((int) $followUp['requires_follow_up'])->toBeGreaterThan(2);

    $otherDashboard = $this->actingAs($otherManager->user, 'sanctum')
        ->getJson('/api/manager/dashboard')
        ->assertOk();

    expect((int) $otherDashboard->json('payment_follow_up.action_required'))->toBe(1)
        ->and((int) $otherDashboard->json('payment_follow_up.overdue'))->toBe(1)
        ->and((int) $otherDashboard->json('payment_follow_up.due_today'))->toBe(0);

    $director = paymentFollowUpDirector();
    $directorDashboard = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/dashboard')
        ->assertOk();

    expect((int) $directorDashboard->json('company_summary.payment_follow_up.action_required'))->toBeGreaterThanOrEqual(3);

    $list = $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/payment-follow-ups')
        ->assertOk();

    $names = collect($list->json('data'))->pluck('dealer_name')->all();
    $employeeNames = collect($list->json('employees'))->pluck('employee_name')->all();

    expect($names)->toContain('Manager Overdue Dealer')
        ->and($names)->toContain('Manager Due Today Dealer')
        ->and($names)->toContain('Manager Upcoming Dealer')
        ->and($names)->not->toContain('Other Manager Overdue Dealer')
        ->and($list->json('summary.overdue_dealers'))->toBe(1)
        ->and($list->json('summary.commitments_due_today'))->toBe(1)
        ->and($employeeNames)->toContain($report->full_name)
        ->and($employeeNames)->not->toContain($foreignReport->full_name)
        ->and($employeeNames)->not->toContain($otherManager->full_name);

    $filtered = $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/payment-follow-ups?employee_id='.$report->id)
        ->assertOk();

    expect(collect($filtered->json('data'))->pluck('dealer_name')->all())
        ->toContain('Manager Overdue Dealer')
        ->and(collect($filtered->json('data'))->pluck('dealer_name')->all())
        ->not->toContain('Other Manager Overdue Dealer');

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/payment-follow-ups?employee_id='.$foreignReport->id)
        ->assertForbidden();

    $history = $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/payment-follow-ups/'.$overdueDealer->id)
        ->assertOk();

    expect($history->json('can_add_follow_up'))->toBeFalse()
        ->and($history->json('follow_up_count'))->toBe(1)
        ->and($history->json('cycles.0.entries.0.remark'))->toBe('Missed commitment');

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/payment-follow-ups/'.$foreignOverdue->id)
        ->assertForbidden();

    $blocked = $this->actingAs($manager->user, 'sanctum')
        ->postJson('/api/manager/payment-follow-ups/'.$overdueDealer->id, [
            'remark' => 'Manager must not save',
            'next_follow_up_date' => '2026-09-20',
        ]);

    expect($blocked->status())->toBeIn([404, 405]);

    $this->actingAs($report->user, 'sanctum')
        ->getJson('/api/manager/payment-follow-ups')
        ->assertForbidden();

    $this->actingAs($report->user, 'sanctum')
        ->postJson('/api/employee/payment-follow-ups/'.$noFollowUpDealer->id, [
            'remark' => 'Employee workflow still works',
            'expected_amount' => 8000,
            'next_follow_up_date' => '2026-09-22',
        ])
        ->assertCreated();
});

it('returns zero manager payment follow-up action required when the manager has no reports', function (): void {
    $manager = paymentFollowUpManager('9811300311');
    $other = paymentFollowUpEmployee('9811300312');
    paymentFollowUpDealer($other, 'Unrelated Overdue Dealer');

    $dashboard = $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/dashboard')
        ->assertOk();

    expect((int) $dashboard->json('payment_follow_up.action_required'))->toBe(0)
        ->and((int) $dashboard->json('payment_follow_up.overdue'))->toBe(0)
        ->and((int) $dashboard->json('payment_follow_up.due_today'))->toBe(0);

    $list = $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/payment-follow-ups')
        ->assertOk();

    expect($list->json('data'))->toBe([])
        ->and($list->json('employees'))->toBe([]);
});
