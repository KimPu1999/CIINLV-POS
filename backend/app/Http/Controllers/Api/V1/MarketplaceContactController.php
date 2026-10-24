<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\StoreContactInquiryRequest;
use App\Models\MarketplaceContactInquiry;
use Illuminate\Http\JsonResponse;

class MarketplaceContactController
{
    public function store(StoreContactInquiryRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['website'])) {
            return response()->json(['success' => true, 'data' => ['accepted' => true]], 202);
        }

        $inquiry = MarketplaceContactInquiry::create([
            'name' => trim(strip_tags($data['name'])),
            'email' => trim($data['email']),
            'phone' => isset($data['phone']) ? trim(strip_tags($data['phone'])) : null,
            'subject' => trim(strip_tags($data['subject'])),
            'message' => trim(strip_tags($data['message'])),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['accepted' => true, 'id' => $inquiry->getKey()],
        ], 201);
    }
}
