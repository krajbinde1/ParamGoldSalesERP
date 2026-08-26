<?php

namespace App\Support\CreditNotes;

use App\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CreditNotePayloadValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(Request $request, bool $documentRequired = false): array
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
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.reason' => ['nullable', 'string', 'max:2000'],
        ];

        if ($type === CreditNote::TYPE_SALES_RETURN) {
            $itemRules['items.*.rate'] = ['required', 'numeric', 'min:0'];
            $itemRules['items.*.original_rate'] = ['prohibited'];
            $itemRules['items.*.revised_rate'] = ['prohibited'];
        } elseif ($type === CreditNote::TYPE_RATE_DIFFERENCE) {
            $itemRules['items.*.original_rate'] = ['required', 'numeric', 'min:0'];
            $itemRules['items.*.revised_rate'] = ['required', 'numeric', 'min:0'];
            $itemRules['items.*.rate'] = ['prohibited'];
        }

        $documentRule = $documentRequired
            ? ['required']
            : ['nullable'];

        return $request->validate(array_merge([
            'type' => ['required', 'string', Rule::in([
                CreditNote::TYPE_SALES_RETURN,
                CreditNote::TYPE_RATE_DIFFERENCE,
            ])],
            'dealer_id' => ['required', 'integer', 'exists:dealers,id'],
            'bill_reference' => ['required', 'string', 'max:100'],
            'credit_note_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'supporting_document' => array_merge($documentRule, [
                'file',
                'mimes:jpeg,jpg,png,webp,pdf',
                'max:5120',
            ]),
        ], $itemRules));
    }
}
