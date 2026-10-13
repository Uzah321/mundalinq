<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Addresses\SaveAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's delivery addresses. Exactly one is the default once any exist.
 */
class AddressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection($request->user()->addresses()->get());
    }

    public function store(SaveAddressRequest $request): AddressResource
    {
        $user = $request->user();

        $address = DB::transaction(function () use ($user, $request) {
            $first = ! $user->addresses()->exists();
            $address = $user->addresses()->create([...$request->validated(), 'is_default' => false]);

            if ($first || $request->boolean('is_default')) {
                $this->makeDefault($address);
            }

            return $address;
        });

        return new AddressResource($address);
    }

    public function update(SaveAddressRequest $request, UserAddress $address): AddressResource
    {
        $this->ensureOwned($request, $address);

        if ($address->is_default && $request->has('is_default') && ! $request->boolean('is_default')) {
            abort(422, __('Choose another default address instead.'));
        }

        DB::transaction(function () use ($request, $address) {
            $address->update(collect($request->validated())->except('is_default')->all());

            if ($request->boolean('is_default')) {
                $this->makeDefault($address);
            }
        });

        return new AddressResource($address->refresh());
    }

    public function destroy(Request $request, UserAddress $address): Response
    {
        $this->ensureOwned($request, $address);

        DB::transaction(function () use ($request, $address) {
            $address->delete();

            if ($address->is_default && ($next = $request->user()->addresses()->latest()->first())) {
                $this->makeDefault($next);
            }
        });

        return response()->noContent();
    }

    private function makeDefault(UserAddress $address): void
    {
        UserAddress::where('user_id', $address->user_id)->whereKeyNot($address->id)->update(['is_default' => false]);
        $address->forceFill(['is_default' => true])->save();
    }

    private function ensureOwned(Request $request, UserAddress $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 404);
    }
}
