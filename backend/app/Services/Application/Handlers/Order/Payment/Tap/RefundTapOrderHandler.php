<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Tap;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Exceptions\Tap\TapApiException;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Models\TapPayment;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Domain\Order\OfflineOrderRefundService;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Infrastructure\Lock\TransactionLockService;
use HiEvents\Services\Infrastructure\Tap\TapClient;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

/**
 * Organizer-initiated refund of an order paid through Tap. Tap answers the
 * refund call synchronously, so the refund is recorded straight away with the
 * same bookkeeping as an offline refund.
 */
class RefundTapOrderHandler
{
    private const FAILED_REFUND_STATUSES = ['FAILED', 'DECLINED', 'CANCELLED', 'REJECTED'];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderCancelService $orderCancelService,
        private readonly OfflineOrderRefundService $refundRecorder,
        private readonly TransactionLockService $transactionLockService,
        private readonly TapClient $tapClient,
        private readonly Mailer $mailer,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws RefundNotPossibleException
     * @throws Throwable
     */
    public function handle(RefundOrderDTO $dto): OrderDomainObject
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            $order = $this->orderRepository->findFirstWhere(['event_id' => $dto->event_id, 'id' => $dto->order_id]);
            if (! $order) {
                throw new ResourceNotFoundException(__('Order :id not found for event :eventId', [
                    'id' => $dto->order_id,
                    'eventId' => $dto->event_id,
                ]));
            }

            $this->transactionLockService->lockOrder($order->getShortId());
            $order = $this->orderRepository->findById($order->getId());

            /** @var TapPayment|null $payment */
            $payment = TapPayment::where('order_id', $order->getId())->where('status', TapClient::STATUS_CAPTURED)->latest('id')->first();

            if ($payment === null) {
                throw new RefundNotPossibleException(__('There is no captured Tap payment for this order.'));
            }
            if ($order->getRefundStatus() === OrderRefundStatus::REFUNDED->name) {
                throw new RefundNotPossibleException(__('This order has already been fully refunded.'));
            }
            if ($dto->amount <= 0 || $dto->amount > $order->getTotalGross() - $order->getTotalRefunded() + 0.0001) {
                throw new RefundNotPossibleException(__('The refund amount cannot exceed the amount available to refund.'));
            }

            $amount = TapClient::formatAmount($dto->amount, $payment->currency);

            try {
                $refund = $this->tapClient->createRefund([
                    'charge_id' => $payment->charge_id,
                    'amount' => (float) $amount,
                    'currency' => $payment->currency,
                    'reason' => 'requested_by_customer',
                    'reference' => ['merchant' => sprintf('order-%d-%s', $order->getId(), now()->format('YmdHis'))],
                    'metadata' => ['order_id' => (string) $order->getId()],
                ]);
            } catch (TapApiException $e) {
                throw new RefundNotPossibleException(__('Tap rejected the refund: :message', ['message' => $e->getMessage()]));
            }

            if (in_array(strtoupper((string) ($refund['status'] ?? '')), self::FAILED_REFUND_STATUSES, true)) {
                throw new RefundNotPossibleException(__('Tap rejected the refund: :message', [
                    'message' => $refund['response']['message'] ?? $refund['status'],
                ]));
            }

            if ($dto->cancel_order && ! $order->isOrderCancelled()) {
                $this->orderCancelService->cancelOrder($order);
            }

            $money = MoneyValue::fromFloat((float) $amount, $order->getCurrency());
            $this->refundRecorder->refundOrder($order, $money, PaymentProviders::TAP, $refund['id'] ?? null);

            if ($dto->notify_buyer && $order->getEmail() !== null) {
                $this->notifyBuyer($order, $money);
            }

            return $this->orderRepository->findById($order->getId());
        });
    }

    private function notifyBuyer(OrderDomainObject $order, MoneyValue $amount): void
    {
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($order->getEventId());

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send(new OrderRefunded(
                order: $order,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
                refundAmount: $amount,
            ));
    }
}
