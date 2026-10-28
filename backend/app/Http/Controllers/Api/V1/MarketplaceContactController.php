<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\StoreContactInquiryRequest;
use App\Models\MarketplaceContactInquiry;
use App\Models\MarketplaceStore;
use App\Services\BusinessNotificationService;
use App\Services\MarketplaceContactDeliveryService;
use App\Jobs\SendMarketplaceContactEmail;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceContactController
{
    public function store(StoreContactInquiryRequest $request, BusinessNotificationService $notifications, MarketplaceContactDeliveryService $delivery): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['website'])) {
            return response()->json(['success' => true, 'data' => ['accepted' => true]], 202);
        }

        $storeQuery = MarketplaceStore::query()->public();
        if (! empty($data['store'])) {
            $storeQuery->where('slug', $data['store']);
            if (Str::isUuid((string) $data['store'])) {
                $storeQuery = MarketplaceStore::query()->public()->where('uuid', $data['store']);
            }
        }
        $store = ! empty($data['store'])
            ? $storeQuery->first()
            : ($storeQuery->count() === 1 ? $storeQuery->first() : null);
        if (! $store) {
            throw ValidationException::withMessages(['store' => ['Select an approved business before sending your inquiry.']]);
        }

        $name = trim(strip_tags($data['name']));
        $email = trim($data['email']);
        $subject = trim(strip_tags($data['subject']));
        // Keep the customer's message intact. It is rendered as escaped/plain text in
        // the admin UI and mail view, so preserving it does not require storing HTML.
        $message = (string) $data['message'];
        $duplicate = MarketplaceContactInquiry::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('marketplace_store_id', $store->id)
            ->where('email', $email)
            ->where('subject', $subject)
            ->where('message', $message)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest()
            ->first();

        if ($duplicate) {
            return response()->json(['success' => true, 'data' => [
                'accepted' => true,
                'id' => $duplicate->getKey(),
                'email_delivery_status' => $duplicate->admin_notification_status,
                'duplicate' => true,
            ]], 202);
        }

        $supportEmail = $delivery->storeSupportEmail($store, $store->tenant);
        $inquiry = DB::transaction(function () use ($data, $store, $name, $email, $subject, $message, $supportEmail, $delivery): MarketplaceContactInquiry {
            $inquiry = MarketplaceContactInquiry::create([
                'tenant_id' => $store->tenant_id,
                'marketplace_store_id' => $store->id,
                'name' => $name,
                'email' => $email,
                'phone' => isset($data['phone']) ? trim(strip_tags($data['phone'])) : null,
                'subject' => $subject,
                'message' => $message,
                'status' => MarketplaceContactInquiry::STATUS_PENDING,
                'admin_notification_status' => $delivery->mailConfigured() && $supportEmail
                    ? MarketplaceContactInquiry::DELIVERY_QUEUED
                    : MarketplaceContactInquiry::DELIVERY_NOT_CONFIGURED,
            ]);

            if ($delivery->mailConfigured() && $supportEmail) {
                SendMarketplaceContactEmail::dispatch('admin_notification', (int) $inquiry->getKey(), $supportEmail)->afterCommit();
            }

            return $inquiry;
        });

        $notifications->contactInquiryCreated($inquiry->load('store'));

        return response()->json([
            'success' => true,
            'data' => [
                'accepted' => true,
                'id' => $inquiry->getKey(),
                'email_delivery_status' => $inquiry->admin_notification_status,
            ],
        ], 201);
    }
}
