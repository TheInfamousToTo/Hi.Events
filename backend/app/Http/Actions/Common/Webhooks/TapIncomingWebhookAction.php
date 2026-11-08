<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Domain\Payment\Tap\TapPaymentCompletionService;
use HiEvents\Services\Infrastructure\Tap\TapClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Tap server-to-server notification (charge `post.url`). The hashstring
 * header is verified first; even then the charge is re-read from Tap by the
 * completion service, so a forged-but-signed body changes nothing.
 */
class TapIncomingWebhookAction extends BaseAction
{
    public function __invoke(Request $request, TapClient $tapClient): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! $tapClient->isValidWebhookSignature($payload, $request->header('hashstring'))) {
            logger()->warning('Rejected Tap webhook with missing or invalid hashstring', ['ip' => $request->ip()]);

            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        }

        $chargeId = (string) ($payload['id'] ?? '');
        if (! str_starts_with($chargeId, 'chg_')) {
            // Refund / authorize notifications: nothing to complete.
            return $this->noContentResponse();
        }

        dispatch(static function (TapPaymentCompletionService $service) use ($chargeId) {
            $service->process($chargeId);
        })->catch(function (Throwable $exception) use ($chargeId) {
            logger()->error('Failed to handle Tap webhook', ['charge_id' => $chargeId, 'exception' => $exception]);
        });

        return $this->noContentResponse();
    }
}
