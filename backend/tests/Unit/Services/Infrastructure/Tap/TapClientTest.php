<?php

namespace Tests\Unit\Services\Infrastructure\Tap;

use HiEvents\Exceptions\Tap\TapApiException;
use HiEvents\Services\Infrastructure\Tap\TapClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

class TapClientTest extends TestCase
{
    private const SECRET = 'sk_test_unit';

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'chg_TS0123456789',
            'amount' => 12.5,
            'currency' => 'BHD',
            'status' => 'CAPTURED',
            'reference' => ['gateway' => '123456', 'payment' => '9876'],
            'transaction' => ['created' => '1731000000000'],
        ], $overrides);
    }

    private function client(?HttpFactory $http = null, ?string $secret = self::SECRET): TapClient
    {
        return new TapClient($http ?? new HttpFactory, $secret, 'https://api.tap.company/v2');
    }

    public function test_amount_uses_currency_minor_units(): void
    {
        $this->assertSame('12.500', TapClient::formatAmount(12.5, 'BHD'));
        $this->assertSame('3.000', TapClient::formatAmount(3, 'KWD'));
        $this->assertSame('10.00', TapClient::formatAmount(9.999, 'SAR'));
        $this->assertSame('0.10', TapClient::formatAmount(0.1, 'AED'));
    }

    public function test_signing_string_matches_tap_documented_format(): void
    {
        $this->assertSame(
            'x_idchg_TS0123456789x_amount12.500x_currencyBHDx_gateway_reference123456x_payment_reference9876x_statusCAPTUREDx_created1731000000000',
            TapClient::webhookSigningString($this->payload()),
        );
    }

    public function test_valid_signature_is_accepted(): void
    {
        $payload = $this->payload();
        $hash = hash_hmac('sha256', TapClient::webhookSigningString($payload), self::SECRET);

        $this->assertTrue($this->client()->isValidWebhookSignature($payload, $hash));
        $this->assertTrue($this->client()->isValidWebhookSignature($payload, strtoupper($hash)));
    }

    public function test_tampered_or_missing_signature_is_rejected(): void
    {
        $payload = $this->payload();
        $hash = hash_hmac('sha256', TapClient::webhookSigningString($payload), self::SECRET);

        $this->assertFalse($this->client()->isValidWebhookSignature($this->payload(['amount' => 1.5]), $hash));
        $this->assertFalse($this->client()->isValidWebhookSignature($this->payload(['status' => 'FAILED']), $hash));
        $this->assertFalse($this->client()->isValidWebhookSignature($payload, null));
        $this->assertFalse($this->client()->isValidWebhookSignature($payload, ''));
        $this->assertFalse($this->client(secret: 'sk_test_other')->isValidWebhookSignature($payload, $hash));
        $this->assertFalse($this->client(secret: null)->isValidWebhookSignature($payload, $hash));
    }

    public function test_retrieve_rejects_non_charge_ids_without_calling_tap(): void
    {
        $http = new HttpFactory;
        $http->fake();

        try {
            $this->client($http)->retrieveCharge('../refunds/x');
            $this->fail('expected TapApiException');
        } catch (TapApiException) {
            $http->assertNothingSent();
        }
    }

    public function test_requests_use_bearer_secret_key_and_surface_errors(): void
    {
        $http = new HttpFactory;
        $http->fake([
            'api.tap.company/v2/charges/chg_OK1' => $http->response(['id' => 'chg_OK1', 'status' => 'CAPTURED']),
            'api.tap.company/v2/charges/chg_BAD1' => $http->response(['errors' => [['code' => '1108', 'description' => 'Invalid']]], 400),
        ]);
        $client = $this->client($http);

        $this->assertSame('CAPTURED', $client->retrieveCharge('chg_OK1')['status']);
        $http->assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::SECRET));

        $this->expectException(TapApiException::class);
        $this->expectExceptionMessage('1108 Invalid');
        $client->retrieveCharge('chg_BAD1');
    }
}
