<?php

namespace Tests\Unit;

use App\Http\Requests\Marketplace\MarketplaceDisputeRequest;
use App\Models\MarketplaceDispute;
use Tests\TestCase;

class MarketplaceDisputeContractTest extends TestCase
{
    public function test_dispute_contract_exposes_controlled_categories_statuses_and_resolutions(): void
    {
        $this->assertContains('product_not_received', MarketplaceDispute::CATEGORIES);
        $this->assertContains('under_review', MarketplaceDispute::STATUSES);
        $this->assertContains('full_refund', MarketplaceDispute::RESOLUTIONS);
        $this->assertNotContains('paid', MarketplaceDispute::RESOLUTIONS);
    }

    public function test_dispute_request_validates_the_customer_submission_contract(): void
    {
        $rules = (new MarketplaceDisputeRequest())->rules();

        $this->assertArrayHasKey('category', $rules);
        $this->assertArrayHasKey('subject', $rules);
        $this->assertArrayHasKey('description', $rules);
        $this->assertArrayHasKey('requested_resolution', $rules);
    }
}
