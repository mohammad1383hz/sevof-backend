<?php

namespace App\Services\Billing;

use App\Models\Discount;
use App\Models\Payment;
use App\Services\DiscountService;
use App\Services\SubscriptionService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Stripe Checkout (one-time payment per plan period) over the plain REST API.
 *
 * Flow: subscribe() creates a pending Payment + Checkout Session → user pays on Stripe →
 * both the webhook (checkout.session.completed) and the front's confirm call end in fulfill(),
 * which is idempotent, so whichever arrives first activates the plan.
 *
 * Amounts are integer minor units (cents) in config('services.stripe.currency').
 */
class StripeService
{
    private const API = 'https://api.stripe.com/v1';

    /** Seconds a webhook signature timestamp may be old (Stripe's default tolerance). */
    private const SIGNATURE_TOLERANCE = 300;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly DiscountService $discounts,
    ) {
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(Payment $payment, string $description, ?string $email): array
    {
        $front = rtrim((string) config('app.front_url'), '/');

        $response = $this->http()->asForm()->post(self::API.'/checkout/sessions', array_filter([
            'mode' => 'payment',
            'client_reference_id' => (string) $payment->id,
            'customer_email' => $email,
            'success_url' => $front.'/payment/result?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $front.'/payment/result?status=cancelled',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $this->currency(),
                    'unit_amount' => (int) $payment->amount,
                    'product_data' => ['name' => $description],
                ],
            ]],
            'metadata' => ['payment_id' => (string) $payment->id],
            'payment_intent_data' => ['metadata' => ['payment_id' => (string) $payment->id]],
        ], fn ($v) => $v !== null));

        if (! $response->successful() || ! $response->json('url')) {
            Log::warning('stripe.checkout_failed', ['payment_id' => $payment->id, 'body' => $response->json()]);

            throw new RuntimeException($response->json('error.message') ?: 'Could not start the Stripe checkout.');
        }

        return ['id' => (string) $response->json('id'), 'url' => (string) $response->json('url')];
    }

    public function retrieveSession(string $sessionId): array
    {
        $response = $this->http()->get(self::API.'/checkout/sessions/'.rawurlencode($sessionId));

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Stripe session not found.');
        }

        return (array) $response->json();
    }

    /**
     * Marks the payment paid and activates (or queues) the plan exactly once.
     * Returns the payment's final status.
     */
    public function fulfill(array $session): string
    {
        $payment = Payment::where('authority', (string) ($session['id'] ?? ''))->first();

        if (! $payment) {
            return 'failed';
        }

        $paid = ($session['payment_status'] ?? null) === 'paid'
            && (int) ($session['amount_total'] ?? -1) === (int) $payment->amount
            && strtolower((string) ($session['currency'] ?? '')) === $this->currency();

        return DB::transaction(function () use ($payment, $paid, $session) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status !== 'pending') {
                return $payment->status;
            }

            if (! $paid) {
                // An unpaid open session can still be completed later; only expired ones fail.
                if (($session['status'] ?? null) === 'expired') {
                    $payment->update(['status' => 'failed']);

                    return 'failed';
                }

                return 'pending';
            }

            $payment->update([
                'status' => 'success',
                'ref_id' => (string) ($session['payment_intent'] ?? $session['id']),
            ]);

            $this->subscriptions->purchaseSubscription($payment->user, $payment->plan, (int) ($payment->period_months ?: 1));

            if ($payment->discount_id && ($discount = Discount::find($payment->discount_id))) {
                $this->discounts->markPersonalDiscountUsed($discount);
            }

            return 'success';
        });
    }

    /**
     * Verifies the Stripe-Signature header (HMAC-SHA256 over "{t}.{payload}").
     */
    public function verifyWebhook(string $payload, ?string $header): bool
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '' || ! $header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || $signatures === [] || abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function currency(): string
    {
        return strtolower((string) config('services.stripe.currency', 'usd'));
    }

    private function http(): PendingRequest
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw new RuntimeException('Stripe is not configured.');
        }

        return Http::withToken($secret)->acceptJson()->timeout(20);
    }
}
