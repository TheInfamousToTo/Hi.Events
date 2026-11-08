<?php

namespace HiEvents\Http\Actions\Orders\Payment\Tap;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\Tap\TapApiException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Payment\Tap\CreateTapChargeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CreateTapChargeActionPublic extends BaseAction
{
    public function __construct(private readonly CreateTapChargeHandler $handler) {}

    public function __invoke(Request $request, int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $url = $this->handler->handle($eventId, $orderShortId, $request->getPreferredLanguage(['en', 'ar']));
        } catch (ResourceConflictException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (TapApiException $e) {
            logger()->error('Tap charge creation failed', ['order' => $orderShortId, 'error' => $e->getMessage()]);

            return $this->errorResponse(__('Payment could not be started. Please try again.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse(['payment_url' => $url]);
    }
}
