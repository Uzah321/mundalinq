<?php

namespace App\Http\Requests\Orders;

use App\Models\SellerOrder;
use App\Models\Unit;
use App\Rules\DecimalString;
use App\Support\Quantity;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Optional body: {"items": [{"id": "...", "quantity": "20"}]} to supply less than ordered (0 = none).
 */
class AcceptSellerOrderRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('fulfil', $this->sellerOrder());
    }

    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', DecimalString::quantity()->allowingZero()],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $items = $this->sellerOrder()->items()->get()->keyBy('id');
                $fractionalUnits = Unit::where('allows_fraction', true)->pluck('code')->all();

                foreach ($this->input('items', []) as $index => $line) {
                    $item = $items->get($line['id']);
                    $quantity = Quantity::of($line['quantity']);

                    if ($item === null) {
                        $validator->errors()->add("items.{$index}.id", __('This item is not part of the order.'));
                    } elseif ($quantity->isGreaterThan($item->quantity)) {
                        $validator->errors()->add("items.{$index}.quantity", __('You cannot supply more than was ordered.'));
                    } elseif (! in_array($item->unit_code, $fractionalUnits, true) && ! Quantity::isWhole($quantity)) {
                        $validator->errors()->add("items.{$index}.quantity", __('Supply whole :units.', ['units' => $item->unit_plural_name]));
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string> Order item id => quantity.
     */
    public function confirmedQuantities(): array
    {
        return collect($this->validated('items', []))
            ->mapWithKeys(fn (array $line) => [$line['id'] => (string) $line['quantity']])
            ->all();
    }

    private function sellerOrder(): SellerOrder
    {
        return $this->route('sellerOrder');
    }
}
