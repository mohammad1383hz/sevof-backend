<?php

namespace Tests\Unit;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Tests\TestCase;

class PlanBillingPeriodTest extends TestCase
{
    private function proPlan(): Plan
    {
        return (new Plan)->forceFill([
            'price' => 8_000_000,
            'credit' => 530,
            'is_free' => false,
            'discount_percentage' => 10,
            'quarterly_discount_percentage' => 20,
            'semiannual_discount_percentage' => 30,
        ]);
    }

    public function test_period_prices_use_their_own_discount(): void
    {
        $plan = $this->proPlan();

        $this->assertSame(7_200_000, $plan->final_price);
        $this->assertSame(7_200_000, $plan->priceFor(1));
        $this->assertSame(24_000_000, $plan->originalPriceFor(3));
        $this->assertSame(19_200_000, $plan->priceFor(3));
        $this->assertSame(33_600_000, $plan->priceFor(6));
        $this->assertSame(1590.0, $plan->creditFor(3));
    }

    public function test_free_plan_is_monthly_only(): void
    {
        $service = new SubscriptionService;
        $free = (new Plan)->forceFill(['price' => 0, 'credit' => 36, 'is_free' => true]);

        $this->assertSame([1], $free->availablePeriods());
        $this->assertSame(1, $service->normalizePeriod($free, 6));
        $this->assertSame(6, $service->normalizePeriod($this->proPlan(), 6));
        $this->assertSame(1, $service->normalizePeriod($this->proPlan(), 2));
    }

    public function test_subscription_credit_limit_falls_back_to_plan_credit(): void
    {
        $legacy = (new Subscription)->forceFill(['credit_used' => 100])->setRelation('plan', $this->proPlan());
        $quarterly = (new Subscription)->forceFill(['credit_used' => 1000, 'credit_limit' => 1590])->setRelation('plan', $this->proPlan());

        $this->assertSame(430.0, $legacy->remainingCredit());
        $this->assertSame(590.0, $quarterly->remainingCredit());
    }

    public function test_resource_exposes_all_periods(): void
    {
        $periods = (new PlanResource($this->proPlan()))->toArray(new Request)['periods'];

        $this->assertSame([1, 3, 6], array_column($periods, 'months'));
        $this->assertSame(5_600_000, $periods[2]['monthly_price']);
        $this->assertSame(30, $periods[2]['discount_percentage']);
    }
}
