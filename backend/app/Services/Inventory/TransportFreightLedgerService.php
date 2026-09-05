<?php

namespace App\Services\Inventory;

use App\Enums\TransportFreightLedgerType;
use App\Models\Purchase;
use App\Models\TransportFreightLedger;
use App\Models\User;

final class TransportFreightLedgerService
{
    public function postCharge(Purchase $purchase, User $user): ?TransportFreightLedger
    {
        $amount = round((float) $purchase->transport_cost, 2);
        if ($amount <= 0) {
            return null;
        }

        if ($this->netPostedAmount($purchase) > 0.001) {
            return null;
        }

        return TransportFreightLedger::query()->create(
            $this->ledgerAttributes($purchase, $user, TransportFreightLedgerType::Charge, $amount, $this->chargeRemarks($purchase)),
        );
    }

    public function reverse(Purchase $purchase, User $user, string $remarks): ?TransportFreightLedger
    {
        $net = $this->netPostedAmount($purchase);
        if ($net <= 0.001) {
            return null;
        }

        return TransportFreightLedger::query()->create(
            $this->ledgerAttributes($purchase, $user, TransportFreightLedgerType::Reversal, $net, $remarks),
        );
    }

    public function netPostedAmount(Purchase $purchase): float
    {
        $charges = (float) TransportFreightLedger::query()
            ->where('purchase_id', $purchase->id)
            ->where('transaction_type', TransportFreightLedgerType::Charge)
            ->sum('amount');

        $reversals = (float) TransportFreightLedger::query()
            ->where('purchase_id', $purchase->id)
            ->where('transaction_type', TransportFreightLedgerType::Reversal)
            ->sum('amount');

        return round($charges - $reversals, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerAttributes(
        Purchase $purchase,
        User $user,
        TransportFreightLedgerType $type,
        float $amount,
        string $remarks,
    ): array {
        return [
            'transaction_date' => $purchase->purchase_date,
            'transaction_type' => $type,
            'purchase_id' => $purchase->id,
            'purchase_number' => $purchase->purchase_number,
            'supplier_id' => $purchase->supplier_id,
            'supplier_name' => $purchase->displaySupplierName(),
            'transporter_name' => $purchase->transporter_name,
            'transport_invoice_lr_no' => $purchase->transport_invoice_lr_no,
            'amount' => round($amount, 2),
            'remarks' => $remarks,
            'created_by' => $user->id,
        ];
    }

    private function chargeRemarks(Purchase $purchase): string
    {
        return trim(implode(' | ', array_filter([
            'Transport/Freight Charges for '.$purchase->purchase_number,
            filled($purchase->transporter_name) ? 'Transporter: '.$purchase->transporter_name : null,
            filled($purchase->transport_invoice_lr_no) ? 'LR/Invoice: '.$purchase->transport_invoice_lr_no : null,
            filled($purchase->transport_remark) ? $purchase->transport_remark : null,
        ])));
    }
}
