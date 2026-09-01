<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Jobs\SendWhatsAppOutboundMessage;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Employee;
use App\Models\Order;
use App\Models\WhatsAppOutboundMessage;
use App\Services\WhatsApp\WhatsAppOutboundEnqueueService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function waEmployee(string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'WA '.$mobile,
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

function waDealer(Employee $employee, array $overrides = []): Dealer
{
    return Dealer::query()->create(array_merge([
        'firm_name' => 'WA Dealer '.uniqid(),
        'owner_name' => 'Owner',
        'mobile' => '9876543210',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
        'opening_balance' => 0,
    ], $overrides));
}

function waPendingOrder(Dealer $dealer, Employee $employee, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => '2026-08-20',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_FOR_BILLING,
        'payment_type' => 'Credit',
        'subtotal' => 10000,
        'discount_amount' => 0,
        'gst_amount' => 1800,
        'round_off' => 0,
        'grand_total' => 11800,
    ], $overrides));
}

function waBillFile(string $name = 'bill.pdf'): string
{
    Storage::fake('public');
    $path = 'order-bills/'.$name;
    Storage::disk('public')->put($path, '%PDF-1.4 test bill');

    return $path;
}

function waPendingCollection(Dealer $dealer, Employee $employee, array $overrides = []): Collection
{
    $suffix = (string) random_int(1000, 999999);

    return Collection::query()->create(array_merge([
        'receipt_no' => 'RCP-WA-'.$suffix,
        'collection_date' => '2026-08-21',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 5000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'UPI',
        'transaction_number' => 'TXN-WA-'.$suffix,
    ], $overrides));
}

it('queues one bill whatsapp message when an order is marked billed', function (): void {
    Queue::fake();
    Http::fake();

    $employee = waEmployee('9814000001');
    $dealer = waDealer($employee, ['firm_name' => 'Shree Ganesh Traders', 'mobile' => '9123456789']);
    $order = waPendingOrder($dealer, $employee);
    $path = waBillFile('ganesh.pdf');

    $order->markAsBilled(
        billPath: $path,
        billNumber: 'BILL-WA-1',
        billDate: '2026-08-29',
    );

    $message = WhatsAppOutboundMessage::query()
        ->where('erp_reference', 'WA-BILL-'.$order->id)
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->source_type)->toBe(WhatsAppOutboundMessage::SOURCE_BILL)
        ->and($message->status)->toBe(WhatsAppOutboundMessage::STATUS_PENDING)
        ->and($message->to_number)->toBe('+919123456789')
        ->and($message->payload['dealer_name'])->toBe('Shree Ganesh Traders')
        ->and($message->payload['bill_number'])->toBe('BILL-WA-1')
        ->and($message->payload['bill_date'])->toBe('2026-08-29')
        ->and($message->payload['grand_total'])->toEqual(11800.0)
        ->and($message->payload['media_kind'])->toBe('document')
        ->and($message->payload['body'])->toContain('Shree Ganesh Traders')
        ->and($message->payload['body'])->toContain('BILL-WA-1')
        ->and(DealerTallyEntry::query()->where('source_id', $order->id)->count())->toBe(0);

    Queue::assertPushed(SendWhatsAppOutboundMessage::class, fn (SendWhatsAppOutboundMessage $job): bool => $job->messageId === $message->id);
    Http::assertNothingSent();

    app(WhatsAppOutboundEnqueueService::class)->queueBilledOrder($order->fresh());
    expect(WhatsAppOutboundMessage::query()->where('source_id', $order->id)->where('source_type', WhatsAppOutboundMessage::SOURCE_BILL)->count())->toBe(1);
});

it('does not call meta when credentials are missing and leaves the message pending', function (): void {
    Http::fake();
    $employee = waEmployee('9814000002');
    $dealer = waDealer($employee);
    $order = waPendingOrder($dealer, $employee);
    $order->markAsBilled(billPath: waBillFile('pending.pdf'), billNumber: 'BILL-PEND', billDate: '2026-08-29');

    $message = WhatsAppOutboundMessage::query()->where('erp_reference', 'WA-BILL-'.$order->id)->first();

    expect($message?->status)->toBe(WhatsAppOutboundMessage::STATUS_PENDING)
        ->and($message?->meta_message_id)->toBeNull();
    Http::assertNothingSent();
});

it('fails enqueue when dealer mobile is invalid without calling meta', function (): void {
    Http::fake();
    Queue::fake();
    $employee = waEmployee('9814000003');
    $dealer = waDealer($employee, ['mobile' => '12345']);
    $order = waPendingOrder($dealer, $employee);
    $order->markAsBilled(billPath: waBillFile('bad-mobile.pdf'), billNumber: 'BILL-BAD', billDate: '2026-08-29');

    $message = WhatsAppOutboundMessage::query()->where('erp_reference', 'WA-BILL-'.$order->id)->first();

    expect($message?->status)->toBe(WhatsAppOutboundMessage::STATUS_FAILED)
        ->and($message?->error)->toBe(WhatsAppOutboundEnqueueService::ERROR_INVALID_MOBILE);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('queues one payment received whatsapp message when a collection is marked received', function (): void {
    Queue::fake();
    Http::fake();
    $employee = waEmployee('9814000004');
    $dealer = waDealer($employee, ['firm_name' => 'Pay Dealer', 'mobile' => '9988776655']);
    $collection = waPendingCollection($dealer, $employee);

    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $message = WhatsAppOutboundMessage::query()
        ->where('erp_reference', 'WA-RCV-'.$collection->id)
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->status)->toBe(WhatsAppOutboundMessage::STATUS_PENDING)
        ->and($message->to_number)->toBe('+919988776655')
        ->and($message->payload['dealer_name'])->toBe('Pay Dealer')
        ->and($message->payload['amount'])->toEqual(5000.0)
        ->and($message->payload['receipt_no'])->toBe($collection->receipt_no)
        ->and($message->payload['collection_date'])->toBe('2026-08-21')
        ->and($message->payload['body'])->toContain('Pay Dealer')
        ->and($message->payload['body'])->toContain($collection->receipt_no);

    Queue::assertPushed(SendWhatsAppOutboundMessage::class);
    Http::assertNothingSent();
});

it('withdraws a pending collection whatsapp message when status leaves received', function (): void {
    Queue::fake();
    $employee = waEmployee('9814000005');
    $dealer = waDealer($employee);
    $collection = waPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $message = WhatsAppOutboundMessage::query()->where('erp_reference', 'WA-RCV-'.$collection->id)->firstOrFail();
    expect($message->status)->toBe(WhatsAppOutboundMessage::STATUS_PENDING);

    $collection->update(['status' => Collection::STATUS_NOT_RECEIVED, 'admin_remark' => 'Cheque bounced']);

    expect($message->fresh()->status)->toBe(WhatsAppOutboundMessage::STATUS_FAILED)
        ->and($message->fresh()->error)->toBe(WhatsAppOutboundEnqueueService::ERROR_WITHDRAWN)
        ->and(WhatsAppOutboundMessage::query()->where('source_id', $collection->id)->count())->toBe(1);
});

it('sends bill media through the cloud api when credentials are configured', function (): void {
    Http::fake([
        'https://graph.facebook.com/v21.0/123456/media' => Http::response(['id' => 'MEDIA-1'], 200),
        'https://graph.facebook.com/v21.0/123456/messages' => Http::response([
            'messages' => [['id' => 'wamid.BILL1']],
        ], 200),
    ]);
    config()->set([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.token' => 'test-token',
        'services.whatsapp.phone_number_id' => '123456',
        'services.whatsapp.graph_version' => 'v21.0',
        'services.whatsapp.bill_template' => '',
        'services.whatsapp.bill_image_template' => '',
        'services.whatsapp.collection_template' => '',
    ]);

    $employee = waEmployee('9814000006');
    $dealer = waDealer($employee, ['firm_name' => 'Cloud Dealer', 'mobile' => '9000000001']);
    $order = waPendingOrder($dealer, $employee);
    $order->markAsBilled(
        billPath: waBillFile('cloud.pdf'),
        billNumber: 'BILL-CLOUD',
        billDate: '2026-08-29',
    );

    $message = WhatsAppOutboundMessage::query()->where('erp_reference', 'WA-BILL-'.$order->id)->first();

    expect($message?->status)->toBe(WhatsAppOutboundMessage::STATUS_SENT)
        ->and($message?->meta_message_id)->toBe('wamid.BILL1')
        ->and($message?->meta_media_id)->toBe('MEDIA-1')
        ->and($message?->error)->toBeNull();

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/media'));
    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/messages')) {
            return false;
        }

        $data = $request->data();

        return ($data['type'] ?? null) === 'document'
            && ($data['to'] ?? null) === '919000000001'
            && str_contains((string) ($data['document']['caption'] ?? ''), 'Cloud Dealer')
            && str_contains((string) ($data['document']['caption'] ?? ''), 'BILL-CLOUD');
    });
});

it('sends a collection confirmation through the cloud api when credentials are configured', function (): void {
    Http::fake([
        'https://graph.facebook.com/v21.0/123456/messages' => Http::response([
            'messages' => [['id' => 'wamid.RCV1']],
        ], 200),
    ]);
    config()->set([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.token' => 'test-token',
        'services.whatsapp.phone_number_id' => '123456',
        'services.whatsapp.graph_version' => 'v21.0',
        'services.whatsapp.collection_template' => '',
    ]);

    $employee = waEmployee('9814000007');
    $dealer = waDealer($employee, ['firm_name' => 'Rcv Dealer', 'mobile' => '9000000002']);
    $collection = waPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $message = WhatsAppOutboundMessage::query()->where('erp_reference', 'WA-RCV-'.$collection->id)->first();

    $receiptNo = (string) $collection->receipt_no;

    expect($message?->status)->toBe(WhatsAppOutboundMessage::STATUS_SENT)
        ->and($message?->meta_message_id)->toBe('wamid.RCV1');

    Http::assertSent(function ($request) use ($receiptNo): bool {
        $data = $request->data();

        return ($data['type'] ?? null) === 'text'
            && ($data['to'] ?? null) === '919000000002'
            && str_contains((string) ($data['text']['body'] ?? ''), 'Rcv Dealer')
            && str_contains((string) ($data['text']['body'] ?? ''), $receiptNo);
    });
});

it('stores the exact meta error when send fails', function (): void {
    Http::fake([
        'https://graph.facebook.com/v21.0/123456/messages' => Http::response([
            'error' => [
                'message' => 'Template name does not exist in the translation',
                'code' => 132001,
            ],
        ], 400),
    ]);
    config()->set([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.token' => 'test-token',
        'services.whatsapp.phone_number_id' => '123456',
        'services.whatsapp.graph_version' => 'v21.0',
    ]);

    $employee = waEmployee('9814000008');
    $dealer = waDealer($employee);
    $collection = waPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $message = WhatsAppOutboundMessage::query()->where('erp_reference', 'WA-RCV-'.$collection->id)->first();

    expect($message?->status)->toBe(WhatsAppOutboundMessage::STATUS_FAILED)
        ->and($message?->error)->toContain('Template name does not exist in the translation')
        ->and($message?->error)->toContain('132001');
});
