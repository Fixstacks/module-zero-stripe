<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Zero\Stripe\Model\Config;
use Zero\Stripe\Model\PaymentIntentGateway;
use Zero\Stripe\Model\Standard;

/**
 * capture() must only accept a PaymentIntent that succeeded, belongs to this
 * order's quote, and matches the order's amount and currency; refund() gives
 * money back through Stripe, never only in Magento.
 */
#[AllowMockObjectsWithoutExpectations]
class StandardTest extends TestCase
{
    private Config&MockObject $config;
    private PaymentIntentGateway&MockObject $gateway;
    private Standard $method;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->gateway = $this->createMock(PaymentIntentGateway::class);
        // AbstractMethod falls back to the global ObjectManager for its DirectoryHelper.
        (new ObjectManager($this))->prepareObjectManager([
            [DirectoryHelper::class, $this->createMock(DirectoryHelper::class)],
        ]);
        $this->method = (new ObjectManager($this))->getObject(Standard::class, [
            'stripeConfig' => $this->config,
            'paymentIntentGateway' => $this->gateway,
        ]);
        $this->config->method('getSecretKey')->willReturn('sk_test_123');
    }

    public function testCaptureAcceptsMatchingIntent(): void
    {
        $payment = $this->payment('pi_1', $this->order(19.99, 'USD', 42));
        $this->gateway->expects($this->once())->method('retrieve')
            ->with('sk_test_123', 'pi_1')
            ->willReturn($this->intent('succeeded', 1999, 'usd', '42'));

        $payment->expects($this->once())->method('setTransactionId')->with('pi_1');
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true);

        $this->method->capture($payment, 19.99);
    }

    public function testCaptureRejectsMissingIntentId(): void
    {
        $this->gateway->expects($this->never())->method('retrieve');
        $this->expectException(LocalizedException::class);

        $this->method->capture($this->payment('', $this->order(10, 'USD', 1)), 10);
    }

    public function testCaptureRejectsWhenSecretKeyMissing(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getSecretKey')->willReturn('');
        $method = (new ObjectManager($this))->getObject(Standard::class, [
            'stripeConfig' => $config,
            'paymentIntentGateway' => $this->gateway,
        ]);
        $this->gateway->expects($this->never())->method('retrieve');
        $this->expectExceptionMessage('secret key is required');

        $method->capture($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testCaptureWrapsStripeApiErrors(): void
    {
        $this->gateway->method('retrieve')->willThrowException(InvalidRequestException::factory('No such intent'));
        $this->expectExceptionMessage('couldn\'t verify your card payment');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testCaptureRejectsUnfinishedIntent(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_payment_method', 1000, 'usd', '1'));
        $this->expectExceptionMessage('was not completed');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    /**
     * Replay: a succeeded intent with the right amount, but made for another quote.
     */
    public function testCaptureRejectsIntentFromAnotherQuote(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', 1000, 'usd', '7'));
        $this->expectExceptionMessage('could not be verified for this order');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 8)), 10);
    }

    public function testCaptureRejectsIntentWithoutQuoteMetadata(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', 1000, 'usd', null));
        $this->expectExceptionMessage('could not be verified for this order');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 8)), 10);
    }

    public function testCaptureRejectsAmountMismatch(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', 999, 'usd', '3'));
        $this->expectExceptionMessage('payment amount could not be verified');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 3)), 10);
    }

    public function testCaptureRejectsCurrencyMismatch(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', 1000, 'eur', '3'));
        $this->expectExceptionMessage('payment amount could not be verified');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 3)), 10);
    }

    public function testRefundGoesThroughStripe(): void
    {
        $payment = $this->payment('pi_1', $this->order(19.99, 'USD', 42));
        $this->gateway->expects($this->once())->method('refund')
            ->with('sk_test_123', 'pi_1', 1250)
            ->willReturn(Refund::constructFrom(['id' => 're_1', 'status' => 'succeeded']));

        $payment->expects($this->once())->method('setTransactionId')->with('re_1');
        $payment->expects($this->once())->method('setParentTransactionId')->with('pi_1');

        $this->method->refund($payment, 12.50);
    }

    public function testRefundStopsTheCreditMemoWhenStripeRefuses(): void
    {
        $this->gateway->method('refund')->willThrowException(InvalidRequestException::factory('Charge already refunded'));
        $this->expectExceptionMessage('Charge already refunded');

        $this->method->refund($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testRefundStopsOnAFailedRefund(): void
    {
        $this->gateway->method('refund')->willReturn(Refund::constructFrom(['id' => 're_1', 'status' => 'failed']));
        $this->expectExceptionMessage('refused the refund');

        $this->method->refund($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testRefundNeedsTheIntent(): void
    {
        $this->gateway->expects($this->never())->method('refund');
        $this->expectException(LocalizedException::class);

        $this->method->refund($this->payment('', $this->order(10, 'USD', 1)), 10);
    }

    public function testAssignDataStoresIntentId(): void
    {
        $info = $this->createMock(InfoInterface::class);
        $info->expects($this->once())->method('setAdditionalInformation')
            ->with('stripe_payment_intent_id', 'pi_99');
        $this->method->setInfoInstance($info);

        $this->method->assignData(new DataObject(['additional_data' => ['stripe_payment_intent_id' => 'pi_99']]));
    }

    public function testAssignDataIgnoresEmptyIntentId(): void
    {
        $info = $this->createMock(InfoInterface::class);
        $info->expects($this->never())->method('setAdditionalInformation');
        $this->method->setInfoInstance($info);

        $this->method->assignData(new DataObject(['additional_data' => ['stripe_payment_intent_id' => '']]));
    }

    private function order(float $baseGrandTotal, string $currency, int $quoteId): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getBaseGrandTotal')->willReturn($baseGrandTotal);
        $order->method('getBaseCurrencyCode')->willReturn($currency);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private function payment(string $intentId, Order $order): Payment&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['stripe_payment_intent_id', $intentId],
        ]);

        return $payment;
    }

    private function intent(string $status, int $amount, string $currency, ?string $quoteId): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => 'pi_1',
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => $quoteId === null ? [] : ['quote_id' => $quoteId],
        ]);
    }
}
