<?php

namespace HiEvents\Http\Actions\Orders\Payment\Tap;

use HiEvents\Exceptions\Tap\TapApiException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\TapPayment;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Payment\Tap\TapPaymentCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Called by the checkout when Tap redirects the buyer back with ?tap_id=chg_...
 * The tap_id is only a pointer: the charge must belong to this order, and its
 * state is re-read from Tap before anything changes.
 */
class VerifyTapChargeActionPublic extends BaseAction
{
    public function __construct(
        private readonly TapPaymentCompletionService $completionService,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}

    public function __invoke(Request $request, int $eventId, string $orderShortId): JsonResponse
    {
        $chargeId = (string) $request->query('tap_id', '');
        $order = $this->orderRepository->findByShortId($orderShortId);

        $belongs = $order !== null
            && $order->getEventId() === $eventId
            && preg_match('/^chg_[A-Za-z0-9]+$/', $chargeId)
            && TapPayment::where('charge_id', $chargeId)->where('order_id', $order->getId())->exists();

        if (! $belongs) {
            return $this->errorResponse(__('Payment not found for this order.'), Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->completionService->process($chargeId);
        } catch (TapApiException $e) {
            logger()->error('Tap charge verification failed', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);

            return $this->errorResponse(__('We could not confirm your payment yet. Please refresh in a moment.'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->jsonResponse(['result' => $result]);
    }
}
