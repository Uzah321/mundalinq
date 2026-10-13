<?php

namespace App\Http\Requests\Messaging;

use App\Models\Farm;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Body: {"farm_id": "...", "body": "..."} from a buyer, or {"seller_order_id": "...", "body": "..."} from
 * either side of an order. Optional product_id says which listing the message is about.
 */
class StartConversationRequest extends FormRequest
{
    private ?Farm $farm = null;

    private ?SellerOrder $sellerOrder = null;

    private ?Product $product = null;

    public function rules(): array
    {
        return [
            'farm_id' => ['required_without:seller_order_id', 'nullable', 'uuid'],
            'seller_order_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('seller_order_id')) {
                    $this->sellerOrder = SellerOrder::with(['order', 'farm'])->find($this->input('seller_order_id'));

                    // Hidden from anyone who cannot see the order, like the order itself.
                    abort_if($this->sellerOrder === null || Gate::denies('view', $this->sellerOrder), 404);

                    $this->farm = $this->sellerOrder->farm;
                } else {
                    $this->farm = Farm::find($this->input('farm_id'));

                    abort_if($this->farm === null || Gate::denies('view', $this->farm), 404);

                    if ($this->farm->memberRole($this->user()) !== null) {
                        $validator->errors()->add('farm_id', __('You cannot message your own farm.'));

                        return;
                    }
                }

                if ($this->filled('product_id')) {
                    $this->product = Product::where('farm_id', $this->farm->id)->find($this->input('product_id'));

                    if ($this->product === null) {
                        $validator->errors()->add('product_id', __('This listing is not from this farm.'));
                    }
                }
            },
        ];
    }

    public function farm(): Farm
    {
        return $this->farm;
    }

    /**
     * The buyer's side of the thread: the order's buyer, or the person writing to a farm.
     */
    public function buyer(): User
    {
        return $this->sellerOrder?->order->buyer ?? $this->user();
    }

    public function sellerOrder(): ?SellerOrder
    {
        return $this->sellerOrder;
    }

    public function product(): ?Product
    {
        return $this->product;
    }
}
