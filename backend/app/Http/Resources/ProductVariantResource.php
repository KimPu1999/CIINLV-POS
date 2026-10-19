<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->isSuperAdmin() || $request->user()?->memberships()->where('tenant_id', $this->tenant_id)->where('status', 'active')->whereHas('role', fn ($role) => $role->whereIn('name', ['business_admin', 'inventory_manager']))->exists();
        $balances = $this->relationLoaded('balances') ? $this->balances : collect();

        return ['id' => $this->uuid, 'sku' => $this->sku, 'barcode' => $this->barcode, 'barcode_type' => $this->barcode_type, 'unit' => $this->unit, 'attributes' => $this->attributes, 'purchase_cost' => $canViewCost ? $this->purchase_cost : null, 'selling_price' => $this->selling_price, 'reorder_level' => $this->reorder_level, 'available_stock' => $balances->sum(fn ($balance) => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity), 'stock_by_location' => $balances->map(fn ($balance) => ['location_id' => $balance->inventory_location_id, 'on_hand' => $balance->on_hand_quantity, 'reserved' => $balance->reserved_quantity, 'available' => $balance->available_quantity])->values(), 'status' => $this->status];
    }
}
