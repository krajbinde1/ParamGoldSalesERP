<?php

namespace App\Support\CreditNotes;

use App\Models\CreditNote;
use App\Services\Orders\OrderLineCalculationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CreditNotePayloadValidator
{
    /**
     * @param  list<string>  $allowedTypes
     * @return array<string, mixed>
     */
    public function validate(Request $request, array $allowedTypes, bool $documentRequired = false): array
    {
        $items = $request->input('items');
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (is_array($decoded)) {
                $request->merge(['items' => $decoded]);
            }
        }

        $type = $request->input('type');

        $itemRules = [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.reason' => ['nullable', 'string', 'max:2000'],
        ];

        if ($type === CreditNote::TYPE_SALES_RETURN) {
            $itemRules['items.*.case_quantity'] = ['required', 'integer', 'min:1'];
            $itemRules['items.*.rate_per_no'] = ['nullable', 'numeric', 'min:0'];
            $itemRules['items.*.rate'] = ['nullable', 'numeric', 'min:0'];
            $itemRules['items.*.rate_type'] = ['nullable', 'string', Rule::in([
                OrderLineCalculationService::RATE_TYPE_PRICE_LIST,
                OrderLineCalculationService::RATE_TYPE_FIXED,
                'fixed',
            ])];
            $itemRules['items.*.discount_value'] = ['nullable', 'numeric', 'min:0', 'max:100'];
            $itemRules['items.*.discount_percentage'] = ['nullable', 'numeric', 'min:0', 'max:100'];
            $itemRules['items.*.gst_percentage'] = ['nullable', 'numeric'];
            $itemRules['items.*.original_rate'] = ['prohibited'];
            $itemRules['items.*.revised_rate'] = ['prohibited'];
            $itemRules['items.*.quantity'] = ['nullable', 'numeric'];
        } elseif ($type === CreditNote::TYPE_RATE_DIFFERENCE) {
            $itemRules['items.*.quantity'] = ['required', 'numeric', 'gt:0'];
            $itemRules['items.*.original_rate'] = ['required', 'numeric', 'min:0'];
            $itemRules['items.*.revised_rate'] = ['required', 'numeric', 'min:0'];
            $itemRules['items.*.rate'] = ['prohibited'];
            $itemRules['items.*.case_quantity'] = ['prohibited'];
        }

        $documentRule = $documentRequired
            ? ['required']
            : ['nullable'];

        $moveToRules = $type === CreditNote::TYPE_SALES_RETURN
            ? [
                'move_to' => ['required', 'string', Rule::in([
                    CreditNote::MOVE_TO_FACTORY,
                    CreditNote::MOVE_TO_DEALER,
                ])],
                'destination_dealer_id' => [
                    Rule::requiredIf(fn (): bool => $request->input('move_to') === CreditNote::MOVE_TO_DEALER),
                    'nullable',
                    'integer',
                    'exists:dealers,id',
                    'different:dealer_id',
                ],
            ]
            : [
                'move_to' => ['prohibited'],
                'destination_dealer_id' => ['prohibited'],
            ];

        return $request->validate(array_merge([
            'type' => ['required', 'string', Rule::in($allowedTypes)],
            'dealer_id' => ['required', 'integer', 'exists:dealers,id'],
            'bill_reference' => ['required', 'string', 'max:100'],
            'credit_note_date' => ['nullable', 'date'],
            'client_request_id' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'supporting_document' => array_merge($documentRule, [
                'file',
                'mimes:jpeg,jpg,png,webp,pdf',
                'max:5120',
            ]),
        ], $moveToRules, $itemRules));
    }
}
