<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ManagedProductResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductLifecycle;
use Illuminate\Support\Facades\Gate;

class ProductLifecycleController extends Controller
{
    public function __construct(private ProductLifecycle $lifecycle) {}

    /**
     * Goes live, or to admin review when review applies (the response status says which).
     */
    public function publish(Product $product): ManagedProductResource
    {
        Gate::authorize('manage', $product);

        return $this->respond($this->lifecycle->publish($product));
    }

    public function pause(Product $product): ManagedProductResource
    {
        Gate::authorize('manage', $product);

        return $this->respond($this->lifecycle->pause($product));
    }

    public function resume(Product $product): ManagedProductResource
    {
        Gate::authorize('manage', $product);

        return $this->respond($this->lifecycle->resume($product));
    }

    public function renew(Product $product): ManagedProductResource
    {
        Gate::authorize('manage', $product);

        return $this->respond($this->lifecycle->renew($product));
    }

    private function respond(Product $product): ManagedProductResource
    {
        return new ManagedProductResource($product->load(ProductResource::RELATIONS));
    }
}
