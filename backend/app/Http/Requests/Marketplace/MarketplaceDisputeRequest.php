<?php
namespace App\Http\Requests\Marketplace;
use App\Models\MarketplaceDispute;
use Illuminate\Foundation\Http\FormRequest;
class MarketplaceDisputeRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return ['vendor_order_id' => ['nullable', 'uuid'], 'item_id' => ['nullable', 'uuid'], 'category' => ['required', 'in:'.implode(',', MarketplaceDispute::CATEGORIES)], 'subject' => ['required', 'string', 'min:3', 'max:180'], 'description' => ['required', 'string', 'min:10', 'max:10000'], 'requested_resolution' => ['required', 'in:'.implode(',', MarketplaceDispute::RESOLUTIONS)]];
    }
}
