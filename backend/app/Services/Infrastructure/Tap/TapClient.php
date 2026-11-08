<?php

namespace HiEvents\Services\Infrastructure\Tap;

use Brick\Money\Currency;
use HiEvents\Exceptions\Tap\TapApiException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Thin client for the Tap Payments v2 API (https://developers.tap.company).
 *
 * Rule for callers: a charge is only ever trusted after retrieveCharge() has
 * re-read it from Tap with the secret key. Redirect query strings and webhook
 * bodies are hints about WHICH charge to look at, never proof of payment.
 */
class TapClient
{
    public const STATUS_CAPTURED = 'CAPTURED';

    public const STATUS_INITIATED = 'INITIATED';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $secretKey,
        private readonly string $apiUrl,
    ) {}

    public function isConfigured(): bool
    {
        return ! empty($this->secretKey);
    }

    /**
     * @throws TapApiException
     */
    public function createCharge(array $payload, ?string $langCode = null): array
    {
        $request = $this->request();
        if ($langCode !== null) {
            $request = $request->withHeaders(['lang_code' => $langCode]);
        }

        return $this->decode($request->post('/charges/', $payload), 'create charge');
    }

    /**
     * @throws TapApiException
     */
    public function retrieveCharge(string $chargeId): array
    {
        if (! preg_match('/^chg_[A-Za-z0-9]+$/', $chargeId)) {
            throw new TapApiException('Invalid Tap charge id');
        }

        return $this->decode($this->request()->get('/charges/'.$chargeId), 'retrieve charge');
    }

    /**
     * @throws TapApiException
     */
    public function createRefund(array $payload): array
    {
        return $this->decode($this->request()->post('/refunds/', $payload), 'create refund');
    }

    /**
     * Tap's documented webhook signature: HMAC-SHA256 (hex, secret key) over
     * x_id{id}x_amount{amount}x_currency{currency}x_gateway_reference{..}
     * x_payment_reference{..}x_status{status}x_created{created}
     * with the amount rounded to the currency's standard decimals.
     */
    public function isValidWebhookSignature(array $payload, ?string $hashString): bool
    {
        if (! $this->isConfigured() || empty($hashString) || empty($payload['id']) || empty($payload['currency'])) {
            return false;
        }

        $expected = hash_hmac('sha256', self::webhookSigningString($payload), (string) $this->secretKey);

        return hash_equals($expected, strtolower(trim($hashString)));
    }

    public static function webhookSigningString(array $payload): string
    {
        $currency = (string) $payload['currency'];
        $created = $payload['transaction']['created'] ?? $payload['created'] ?? '';

        return 'x_id'.$payload['id']
            .'x_amount'.self::formatAmount((float) ($payload['amount'] ?? 0), $currency)
            .'x_currency'.$currency
            .'x_gateway_reference'.($payload['reference']['gateway'] ?? '')
            .'x_payment_reference'.($payload['reference']['payment'] ?? '')
            .'x_status'.($payload['status'] ?? '')
            .'x_created'.$created;
    }

    /**
     * Decimal string in the currency's standard minor-unit precision:
     * BHD/KWD/OMR/JOD use 3 decimals ("12.500"), SAR/AED/QAR/USD use 2.
     */
    public static function formatAmount(float $amount, string $currency): string
    {
        $digits = Currency::of(strtoupper($currency))->getDefaultFractionDigits();

        return number_format(round($amount, $digits), $digits, '.', '');
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new TapApiException('Tap Payments is not configured (TAP_SECRET_KEY is empty)');
        }

        return $this->http
            ->baseUrl(rtrim($this->apiUrl, '/'))
            ->withToken((string) $this->secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(5);
    }

    /**
     * @throws TapApiException
     */
    private function decode(Response $response, string $operation): array
    {
        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            $errors = collect($body['errors'] ?? [])->map(fn ($e) => ($e['code'] ?? '').' '.($e['description'] ?? ''))->implode('; ');

            throw new TapApiException(sprintf('Tap %s failed (HTTP %d)%s', $operation, $response->status(), $errors ? ': '.$errors : ''));
        }

        return $body;
    }
}
