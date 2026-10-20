<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\CustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\CustomerAddress;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceCustomerAddressController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => CustomerAddressResource::collection($request->user()->customerProfile->addresses()->orderByDesc('is_default')->orderBy('created_at')->get())]);
    }

    public function store(CustomerAddressRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $profile = $request->user()->customerProfile;
        $data = $request->validated();
        $address = DB::transaction(function () use ($profile, $data): CustomerAddress {
            $isDefault = (bool) ($data['is_default'] ?? false) || ! $profile->addresses()->exists();
            if ($isDefault) {
                $profile->addresses()->update(['is_default' => false]);
            }
            $data['is_default'] = $isDefault;

            return $profile->addresses()->create($data);
        });
        $auditLogger->record('marketplace.customer_address_created', $request->user(), metadata: ['address_id' => $address->id]);

        return response()->json(['success' => true, 'data' => new CustomerAddressResource($address)], 201);
    }

    public function update(CustomerAddressRequest $request, CustomerAddress $address, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless((int) $address->customer_profile_id === (int) $request->user()->customerProfile->id, 404);
        $data = $request->validated();
        DB::transaction(function () use ($address, $data): void {
            if (($data['is_default'] ?? false) === true) {
                $address->customer->addresses()->where('id', '!=', $address->getKey())->update(['is_default' => false]);
            }
            $address->update($data);
        });
        $auditLogger->record('marketplace.customer_address_updated', $request->user(), metadata: ['address_id' => $address->id]);

        return response()->json(['success' => true, 'data' => new CustomerAddressResource($address->refresh())]);
    }

    public function destroy(Request $request, CustomerAddress $address, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless((int) $address->customer_profile_id === (int) $request->user()->customerProfile->id, 404);
        $wasDefault = (bool) $address->is_default;
        $profile = $request->user()->customerProfile;
        $address->delete();
        if ($wasDefault) {
            $profile->addresses()->latest('created_at')->first()?->update(['is_default' => true]);
        }
        $auditLogger->record('marketplace.customer_address_deleted', $request->user(), metadata: ['address_id' => $address->id]);

        return response()->json(['success' => true]);
    }
}
