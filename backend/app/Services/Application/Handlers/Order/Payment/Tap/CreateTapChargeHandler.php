<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Tap;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\Tap\TapApiException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Models\TapPayment;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\PaymentLink\OrderCheckoutAccessService;
use HiEvents\Services\Infrastructure\Tap\TapClient;
use Illuminate\Support\Str;

/**
 * Creates a Tap charge for a reserved order and returns the hosted payment
 * page URL. The buyer pays on Tap's page (cards, Apple Pay, BenefitPay, ...)
 * and is redirected back to the checkout, which then asks the backend to
 * verify the charge with Tap (VerifyTapChargeHandler).
 */
readonly class CreateTapChargeHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private OrderCheckoutAccessService $checkoutAccessService,
        private TapClient $tapClient,
    ) {}

    /**
     * @throws TapApiException
     */
    public function handle(int $eventId, string $orderShortId, ?string $locale = null): string
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(EventDomainObject::class, nested: [
                new Relationship(EventSettingDomainObject::class),
            ], name: 'event'))
            ->findByShortId($orderShortId);

        if (! $order || $order->getEventId() !== $eventId || ! $this->checkoutAccessService->resolve($order)->canPay()) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name
            || $order->isReservedOrderExpired()
            || ! in_array($order->getPaymentStatus(), [OrderPaymentStatus::AWAITING_PAYMENT->name, OrderPaymentStatus::PAYMENT_FAILED->name], true)) {
            throw new ResourceConflictException(__('Sorry, is expired or not in a valid state.'));
        }

        $providers = $order->getEvent()?->getEventSettings()?->getPaymentProviders() ?? [];
        if (is_string($providers)) {
            $providers = json_decode($providers, true) ?: [];
        }
        if (! in_array(PaymentProviders::TAP->value, $providers, true)) {
            throw new ResourceConflictException(__('This payment method is not available for this event.'));
        }

        if (! $this->tapClient->isConfigured()) {
            throw new TapApiException('Tap Payments is not configured');
        }

        $currency = strtoupper($order->getCurrency());
        $amount = TapClient::formatAmount($order->getTotalGross(), $currency);

        // Expire the hosted page with the reservation (Tap allows 5..60 min),
        // so a buyer cannot pay for seats that were already released.
        $minutesLeft = (int) ceil(Carbon::now()->diffInSeconds(new Carbon($order->getReservedUntil()), false) / 60);
        $expiry = max(5, min(60, $minutesLeft));

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $apiUrl = rtrim((string) config('app.url'), '/');

        $customer = array_filter([
            'first_name' => Str::limit($order->getFirstName() ?: 'Guest', 50, ''),
            'last_name' => Str::limit((string) $order->getLastName(), 50, ''),
            'email' => $order->getEmail(),
        ]);

        $charge = $this->tapClient->createCharge([
            'amount' => (float) $amount,
            'currency' => $currency,
            'threeDSecure' => true,
            'save_card' => false,
            'customer_initiated' => true,
            'description' => Str::limit(__('Order :id - :event', [
                'id' => $order->getShortId(),
                'event' => $order->getEvent()?->getTitle() ?? __('Event'),
            ]), 250),
            'metadata' => ['order_id' => (string) $order->getId(), 'event_id' => (string) $eventId],
            'reference' => [
                'transaction' => $order->getShortId(),
                'order' => $order->getShortId(),
            ],
            'customer' => $customer,
            'source' => ['id' => (string) config('services.tap.source_id', 'src_all')],
            'transaction' => ['expiry' => ['period' => $expiry, 'type' => 'MINUTE']],
            'post' => ['url' => $apiUrl.'/api/public/webhooks/tap'],
            'redirect' => ['url' => sprintf('%s/checkout/%d/%s/payment?tap_return=1', $frontendUrl, $eventId, $order->getShortId())],
        ], str_starts_with((string) $locale, 'ar') ? 'ar' : 'en');

        $chargeId = $charge['id'] ?? null;
        $url = $charge['transaction']['url'] ?? null;

        if (! $chargeId || ! $url || ! str_starts_with($url, 'https://')) {
            throw new TapApiException('Tap did not return a payment page for this charge');
        }

        TapPayment::create([
            'order_id' => $order->getId(),
            'charge_id' => $chargeId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $charge['status'] ?? TapClient::STATUS_INITIATED,
            'last_response' => $this->redact($charge),
        ]);

        return $url;
    }

    private function redact(array $charge): array
    {
        unset($charge['customer'], $charge['card'], $charge['source']);

        return $charge;
    }
}
