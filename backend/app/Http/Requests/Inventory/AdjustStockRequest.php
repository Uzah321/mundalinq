<?php

namespace App\Http\Requests\Inventory;

use App\Enums\StockAdjustmentDirection;
use App\Enums\StockAdjustmentReason;
use App\Http\Requests\Catalog\ProductRules;
use App\Models\InventoryBatch;
use App\Rules\DecimalString;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdjustStockRequest extends FormRequest
{
    private ?InventoryBatch $batch = null;

    private bool $batchResolved = false;

    public function authorize(): Response
    {
        $batch = $this->batch();

        if ($batch === null) {
            return Response::allow(); // reported by validation
        }

        // Archived listings no longer accept stock changes.
        return $batch->product ? Gate::inspect('manage', $batch->product) : Response::denyAsNotFound();
    }

    public function rules(): array
    {
        return [
            'batch_id' => ['bail', 'required', 'uuid', Rule::exists('inventory_batches', 'id')],
            'direction' => ['required', Rule::enum(StockAdjustmentDirection::class)],
            'quantity' => ['required', DecimalString::quantity()],
            'reason' => ['required', Rule::enum(StockAdjustmentReason::class)],
            'note' => ['nullable', 'required_if:reason,other', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (StockAdjustmentReason::from($this->input('reason'))->onlyDecreases()
                    && $this->input('direction') !== StockAdjustmentDirection::Decrease->value) {
                    $validator->errors()->add('direction', __('Losses can only reduce stock.'));
                }

                ProductRules::checkWholeStock($validator->errors(), $this->batch()->product->stockUnit, [
                    'quantity' => $this->input('quantity'),
                ]);
            },
        ];
    }

    public function batch(): ?InventoryBatch
    {
        if (! $this->batchResolved) {
            $id = $this->input('batch_id');
            $this->batch = is_string($id) && Str::isUuid($id) ? InventoryBatch::with('product')->find($id) : null;
            $this->batchResolved = true;
        }

        return $this->batch;
    }
}
