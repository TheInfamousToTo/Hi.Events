<?php

namespace HiEvents\Services\Domain\Payment\Tap;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\Tap\TapApiException;
use HiEvents\Models\TapPayment;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\OccurrenceStatusValidator;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Lock\TransactionLockService;
use HiEvents\Services\Infrastructure\Tap\TapClient;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Completes an order from a Tap charge. Called from the buyer's redirect
 * (verify) AND from Tap's webhook, in either order and possibly at once, so it
 * is idempotent and serialised on the order lock.
 *
 * Trust model: the charge is always re-read from Tap with the secret key, and
 * must match the amount, currency and order reference we stored when we
 * created it. Anything else is ignored.
 */
class TapPaymentCompletionService
{
    public const RESULT_COMPLETED = 'COMPLETED';

    public const RESULT_ALREADY_COMPLETED = 'ALREADY_COMPLETED';

    public const RESULT_NOT_PAID = 'NOT_PAID';

    public const RESULT_REFUNDED = 'REFUNDED';

    public function __construct(
        private readonly TapClient $tapClient,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly ProductQuantityUpdateService $quantityUpdateService,
        private readonly OccurrenceStatusValidator $occurrenceStatusValidator,
        private readonly TransactionLockService $transactionLockService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws TapApiException
     * @throws Throwable
     */
    public function process(string $chargeId): string
    {
        /** @var TapPayment|null $payment */
        $payment = TapPayment::where('charge_id', $chargeId)->first();
        if ($payment === null) {
            $this->logger->warning('Tap charge not created by this platform; ignored', ['charge_id' => $chargeId]);

            return self::RESULT_NOT_PAID;
        }

        $charge = $this->tapClient->retrieveCharge($chargeId);
        $status = (string) ($charge['status'] ?? 'UNKNOWN');

        if ($status !== TapClient::STATUS_CAPTURED) {
            $payment->update(['status' => $status, 'last_response' => $this->redact($charge)]);

            return $payment->status === 'REFUNDED' ? self::RESULT_REFUNDED : self::RESULT_NOT_PAID;
        }

        if (! $this->matchesStoredCharge($payment, $charge)) {
            $this->logger->critical('Tap charge does not match the stored charge; order NOT completed', [
                'charge_id' => $chargeId,
                'order_id' => $payment->order_id,
                'stored' => [$payment->amount, $payment->currency],
                'tap' => [$charge['amount'] ?? null, $charge['currency'] ?? null, $charge['reference']['order'] ?? null],
            ]);

            return self::RESULT_NOT_PAID;
        }

        $result = $this->databaseManager->transaction(function () use ($payment, $charge) {
            $order = $this->orderRepository->findById($payment->order_id);
            $this->transactionLockService->lockOrder($order->getShortId());

            $payment->refresh();
            if ($payment->status === TapClient::STATUS_CAPTURED || $payment->status === 'REFUNDED') {
                return ['result' => $payment->status === 'REFUNDED' ? self::RESULT_REFUNDED : self::RESULT_ALREADY_COMPLETED];
            }

            $order = $this->orderRepository->findById($payment->order_id);
            $rejection = $this->rejectionReason($order);

            if ($rejection !== null) {
                $this->refundCapturedCharge($payment, $charge, $rejection);

                return ['result' => self::RESULT_REFUNDED];
            }

            $payment->update(['status' => TapClient::STATUS_CAPTURED, 'last_response' => $this->redact($charge)]);

            $updatedOrder = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->updateFromArray($order->getId(), [
                    OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                    OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
                    OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::TAP->value,
                ]);

            if ($updatedOrder->getAffiliateId()) {
                $this->affiliateRepository->incrementSales(
                    affiliateId: $updatedOrder->getAffiliateId(),
                    amount: $updatedOrder->getTotalGross(),
                );
            }

            $this->attendeeRepository->updateWhere(
                attributes: ['status' => AttendeeStatus::ACTIVE->name],
                where: ['order_id' => $updatedOrder->getId(), 'status' => AttendeeStatus::AWAITING_PAYMENT->name],
            );

            $this->quantityUpdateService->updateQuantitiesFromOrder($updatedOrder);

            /** @var EventSettingDomainObject $eventSettings */
            $eventSettings = $this->eventSettingsRepository->findFirstWhere([
                EventSettingDomainObjectAbstract::EVENT_ID => $updatedOrder->getEventId(),
            ]);

            return ['result' => self::RESULT_COMPLETED, 'order' => $updatedOrder, 'eventSettings' => $eventSettings];
        });

        if ($result['result'] === self::RESULT_COMPLETED) {
            /** @var OrderDomainObject $order */
            $order = $result['order'];
            $hasEmail = $order->getEmail() !== null;

            event(new OrderStatusChangedEvent(
                $order,
                sendEmails: $hasEmail,
                createInvoice: $result['eventSettings']->getEnableInvoicing() && $hasEmail,
            ));

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(type: DomainEventType::ORDER_CREATED, orderId: $order->getId()),
            );

            $this->logger->info('Tap charge captured, order completed', [
                'charge_id' => $payment->charge_id,
                'order_id' => $order->getId(),
            ]);
        }

        return $result['result'];
    }

    public function matchesStoredCharge(TapPayment $payment, array $charge): bool
    {
        $currency = strtoupper((string) ($charge['currency'] ?? ''));
        $order = $this->orderRepository->findById($payment->order_id);

        return ($charge['id'] ?? null) === $payment->charge_id
            && $currency === $payment->currency
            && TapClient::formatAmount((float) ($charge['amount'] ?? -1), $currency) === $payment->amount
            && ($charge['reference']['order'] ?? null) === $order->getShortId();
    }

    private function rejectionReason(OrderDomainObject $order): ?string
    {
        if ($order->getPaymentStatus() === OrderPaymentStatus::PAYMENT_RECEIVED->name) {
            return 'order already paid by another payment';
        }

        if ($order->isOrderAwaitingOfflinePayment()) {
            return 'order was switched to offline payment';
        }

        if (in_array($order->getStatus(), [OrderStatus::CANCELLED->name, OrderStatus::ABANDONED->name], true)) {
            return 'order is cancelled or abandoned';
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name) {
            return 'order is not awaiting payment';
        }

        if ($order->isReservedOrderExpired()) {
            return 'order reservation expired before payment';
        }

        if ($this->occurrenceStatusValidator->findBlockingOccurrence($order) !== null) {
            return 'event date is no longer available';
        }

        return null;
    }

    /**
     * The buyer paid but the order cannot be honoured: give the money back
     * rather than keep it for nothing. Logged loudly for the organizer.
     */
    private function refundCapturedCharge(TapPayment $payment, array $charge, string $reason): void
    {
        $this->logger->error('Tap charge captured for an order that cannot be completed; refunding', [
            'charge_id' => $payment->charge_id,
            'order_id' => $payment->order_id,
            'reason' => $reason,
        ]);

        $refund = $this->tapClient->createRefund([
            'charge_id' => $payment->charge_id,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'reason' => 'requested_by_customer',
            'reference' => ['merchant' => 'auto-'.$payment->charge_id],
            'metadata' => ['order_id' => (string) $payment->order_id, 'reason' => $reason],
        ]);

        $payment->update([
            'status' => 'REFUNDED',
            'refund_id' => $refund['id'] ?? null,
            'last_response' => $this->redact($charge),
        ]);
    }

    private function redact(array $charge): array
    {
        unset($charge['customer'], $charge['card'], $charge['source']);

        return $charge;
    }
}
