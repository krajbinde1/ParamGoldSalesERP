<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppOutboundMessage;
use App\Models\Collection;
use App\Models\Order;
use App\Models\WhatsAppOutboundMessage;
use App\Support\IndianCurrency;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class WhatsAppOutboundEnqueueService
{
    public const ERROR_NO_DEALER = 'This record has no dealer, so it cannot be sent on WhatsApp.';

    public const ERROR_INVALID_MOBILE = 'Dealer mobile number is missing or not a valid 10-digit Indian number.';

    public const ERROR_MISSING_BILL = 'Bill document is missing, so it cannot be sent on WhatsApp.';

    public const ERROR_WITHDRAWN = 'Collection is no longer Received, so it must not be sent on WhatsApp.';

    public function queueBilledOrder(Order $order): ?WhatsAppOutboundMessage
    {
        if ($order->status === Order::STATUS_REJECTED) {
            $this->withdrawUnsynced(
                WhatsAppOutboundMessage::SOURCE_BILL,
                (int) $order->id,
                'Order was rejected, so it must not be sent on WhatsApp.',
            );

            return null;
        }

        if ($order->status !== Order::STATUS_BILLED) {
            return null;
        }

        $order->loadMissing('dealer');
        $phone = WhatsAppPhoneNumber::fromDealer($order->dealer);
        $error = $this->billError($order, $phone);
        $payload = $this->billPayload($order, $phone);

        $message = $this->insertOnce(
            sourceType: WhatsAppOutboundMessage::SOURCE_BILL,
            sourceId: (int) $order->id,
            erpReference: WhatsAppOutboundMessage::billReference((int) $order->id),
            toNumber: $phone,
            payload: $payload,
            enqueueError: $error,
            sendKind: WhatsAppOutboundMessage::SEND_KIND_AUTO,
        );

        $this->dispatchIfPending($message);

        return $message;
    }

    public function resendBilledOrder(Order $order): WhatsAppOutboundMessage
    {
        $order->loadMissing('dealer');
        $phone = WhatsAppPhoneNumber::fromDealer($order->dealer);
        $error = $this->billError($order, $phone);
        $payload = $this->billPayload($order, $phone);
        $payload['resend'] = true;

        $message = WhatsAppOutboundMessage::query()->create([
            'source_type' => WhatsAppOutboundMessage::SOURCE_BILL,
            'source_id' => (int) $order->id,
            'send_kind' => WhatsAppOutboundMessage::SEND_KIND_RESEND,
            'erp_reference' => WhatsAppOutboundMessage::billResendReference((int) $order->id),
            'to_number' => $phone,
            'payload' => $payload,
            'status' => $error === null
                ? WhatsAppOutboundMessage::STATUS_PENDING
                : WhatsAppOutboundMessage::STATUS_FAILED,
            'attempts' => 0,
            'error' => $error,
        ]);

        $this->dispatchIfPending($message);

        return $message;
    }

    public function queueReceivedCollection(Collection $collection): ?WhatsAppOutboundMessage
    {
        if ($collection->status !== Collection::STATUS_RECEIVED) {
            $this->withdrawUnsynced(
                WhatsAppOutboundMessage::SOURCE_COLLECTION,
                (int) $collection->id,
                self::ERROR_WITHDRAWN,
            );

            return null;
        }

        $collection->loadMissing('dealer');
        $phone = WhatsAppPhoneNumber::fromDealer($collection->dealer);
        $error = $this->collectionError($collection, $phone);
        $payload = $this->collectionPayload($collection, $phone);

        $message = $this->insertOnce(
            sourceType: WhatsAppOutboundMessage::SOURCE_COLLECTION,
            sourceId: (int) $collection->id,
            erpReference: WhatsAppOutboundMessage::collectionReference((int) $collection->id),
            toNumber: $phone,
            payload: $payload,
            enqueueError: $error,
            refreshIfUnsynced: true,
        );

        $this->dispatchIfPending($message);

        return $message;
    }

    private function billError(Order $order, ?string $phone): ?string
    {
        if ($order->dealer === null) {
            return self::ERROR_NO_DEALER;
        }

        if ($phone === null) {
            return self::ERROR_INVALID_MOBILE;
        }

        if (blank($order->bill_path) || ! Storage::disk('public')->exists((string) $order->bill_path)) {
            return self::ERROR_MISSING_BILL;
        }

        return null;
    }

    private function collectionError(Collection $collection, ?string $phone): ?string
    {
        if ($collection->dealer === null) {
            return self::ERROR_NO_DEALER;
        }

        if ($phone === null) {
            return self::ERROR_INVALID_MOBILE;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function billPayload(Order $order, ?string $phone): array
    {
        $dealer = $order->dealer;
        $billNumber = filled($order->bill_number)
            ? (string) $order->bill_number
            : (string) $order->order_no;
        $billDate = $order->bill_date?->toDateString()
            ?? $order->billed_at?->timezone('Asia/Kolkata')?->toDateString()
            ?? Carbon::now('Asia/Kolkata')->toDateString();
        $amount = round((float) $order->grand_total, 2);
        $media = $this->mediaFromPath((string) $order->bill_path);

        return [
            'type' => 'bill',
            'dealer_id' => $dealer?->id,
            'dealer_name' => (string) ($dealer?->firm_name ?? ''),
            'to_number' => $phone,
            'order_id' => (int) $order->id,
            'order_no' => (string) $order->order_no,
            'bill_number' => $billNumber,
            'bill_date' => $billDate,
            'grand_total' => $amount,
            'grand_total_label' => IndianCurrency::format($amount),
            'bill_path' => $order->bill_path,
            'media_kind' => $media['kind'],
            'mime_type' => $media['mime'],
            'filename' => $media['filename'] ?: ($billNumber.'.pdf'),
            'body' => WhatsAppBillCopy::body(
                (string) ($dealer?->firm_name ?? 'Dealer'),
                (string) $order->order_no,
                $amount,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPayload(Collection $collection, ?string $phone): array
    {
        $dealer = $collection->dealer;
        $receiptNo = filled($collection->receipt_no)
            ? (string) $collection->receipt_no
            : 'COL-'.$collection->id;
        $date = $collection->collection_date?->toDateString()
            ?: Carbon::now('Asia/Kolkata')->toDateString();
        $amount = round((float) $collection->amount, 2);

        return [
            'type' => 'collection',
            'dealer_id' => $dealer?->id,
            'dealer_name' => (string) ($dealer?->firm_name ?? ''),
            'to_number' => $phone,
            'collection_id' => (int) $collection->id,
            'receipt_no' => $receiptNo,
            'collection_date' => $date,
            'amount' => $amount,
            'amount_label' => IndianCurrency::format($amount),
            'body' => $this->collectionBody(
                (string) ($dealer?->firm_name ?? 'Dealer'),
                $amount,
                $receiptNo,
                $date,
            ),
        ];
    }

    /**
     * @return array{kind: string, mime: string, filename: string}
     */
    private function mediaFromPath(string $path): array
    {
        $filename = basename(str_replace('\\', '/', $path));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => ['kind' => 'document', 'mime' => 'application/pdf', 'filename' => $filename],
            'jpg', 'jpeg' => ['kind' => 'image', 'mime' => 'image/jpeg', 'filename' => $filename],
            'png' => ['kind' => 'image', 'mime' => 'image/png', 'filename' => $filename],
            'webp' => ['kind' => 'image', 'mime' => 'image/webp', 'filename' => $filename],
            default => ['kind' => 'document', 'mime' => 'application/octet-stream', 'filename' => $filename],
        };
    }

    private function collectionBody(string $dealerName, float $amount, string $receiptNo, string $date): string
    {
        return 'Dear '.$dealerName.', we have received payment of '
            .IndianCurrency::format($amount)
            .'. Receipt '.$receiptNo
            .' dated '.$this->displayDate($date).'.';
    }

    private function displayDate(string $date): string
    {
        try {
            return Carbon::parse($date)->timezone('Asia/Kolkata')->format('d M Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertOnce(
        string $sourceType,
        int $sourceId,
        string $erpReference,
        ?string $toNumber,
        array $payload,
        ?string $enqueueError,
        bool $refreshIfUnsynced = false,
        string $sendKind = WhatsAppOutboundMessage::SEND_KIND_AUTO,
    ): WhatsAppOutboundMessage {
        try {
            return DB::transaction(function () use (
                $sourceType,
                $sourceId,
                $erpReference,
                $toNumber,
                $payload,
                $enqueueError,
                $refreshIfUnsynced,
                $sendKind,
            ): WhatsAppOutboundMessage {
                $existing = WhatsAppOutboundMessage::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('send_kind', $sendKind)
                    ->lockForUpdate()
                    ->first();

                $status = $enqueueError === null
                    ? WhatsAppOutboundMessage::STATUS_PENDING
                    : WhatsAppOutboundMessage::STATUS_FAILED;

                if ($existing !== null) {
                    if ($existing->wasAcceptedByProvider() || ! $refreshIfUnsynced || ! $existing->isFailed()) {
                        return $existing;
                    }

                    $existing->fill([
                        'to_number' => $toNumber,
                        'payload' => $payload,
                        'status' => $status,
                        'error' => $enqueueError,
                        'meta_message_id' => null,
                        'meta_media_id' => null,
                        'sent_at' => null,
                        'delivered_at' => null,
                    ]);
                    $existing->save();

                    return $existing;
                }

                return WhatsAppOutboundMessage::query()->create([
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'send_kind' => $sendKind,
                    'erp_reference' => $erpReference,
                    'to_number' => $toNumber,
                    'payload' => $payload,
                    'status' => $status,
                    'attempts' => 0,
                    'error' => $enqueueError,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return WhatsAppOutboundMessage::query()
                ->where('erp_reference', $erpReference)
                ->firstOrFail();
        }
    }

    private function withdrawUnsynced(string $sourceType, int $sourceId, string $error): void
    {
        WhatsAppOutboundMessage::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', WhatsAppOutboundMessage::STATUS_PENDING)
            ->update([
                'status' => WhatsAppOutboundMessage::STATUS_FAILED,
                'error' => $error,
            ]);
    }

    private function dispatchIfPending(WhatsAppOutboundMessage $message): void
    {
        if (! $message->isPending()) {
            return;
        }

        $pending = SendWhatsAppOutboundMessage::dispatch($message->id);

        // Feature tests wrap RefreshDatabase in a transaction that rolls back, so
        // afterCommit would never run. Production still waits for the outer commit.
        if (! app()->runningUnitTests()) {
            $pending->afterCommit();
        }
    }
}
