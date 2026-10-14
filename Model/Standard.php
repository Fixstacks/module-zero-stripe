<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe card payment (zero_stripe). The charge happens client-side
 * (stripe.confirmPayment() in web/js/checkout-stripe.js) before the form is
 * submitted, so capture() only VERIFIES the PaymentIntent: it succeeded, it was
 * created for this order's quote, and its amount/currency match the order —
 * Stripe doesn't know our order total, and the amount was set by our own
 * endpoint, so the match has to be checked here.
 */
class Standard extends AbstractMethod
{
    /**
     * @var string
     */
    protected $_code = 'zero_stripe';

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param PaymentData $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param Config $stripeConfig
     * @param PaymentIntentGateway $paymentIntentGateway
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        private readonly Config $stripeConfig,
        private readonly PaymentIntentGateway $paymentIntentGateway,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Copies stripe_payment_intent_id from additional_data into the payment's
     * additional information — the parent only dispatches an event for that
     * (Braintree registers an observer; one key doesn't justify one here).
     *
     * @param \Magento\Framework\DataObject $data
     * @return $this
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getData('additional_data');
        if (is_array($additionalData)
            && isset($additionalData['stripe_payment_intent_id'])
            && $additionalData['stripe_payment_intent_id'] !== ''
        ) {
            $this->getInfoInstance()->setAdditionalInformation(
                'stripe_payment_intent_id',
                (string) $additionalData['stripe_payment_intent_id']
            );
        }

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        /** @var Order $order */
        $order = $payment->getOrder();

        $intentId = (string) $payment->getAdditionalInformation('stripe_payment_intent_id');
        if ($intentId === '') {
            throw new LocalizedException(
                __('Your card payment could not be verified. Please try again.')
            );
        }

        $secretKey = $this->stripeConfig->getSecretKey((int) $order->getStoreId());
        if ($secretKey === '') {
            throw new LocalizedException(
                __('Stripe is not fully configured — a secret key is required.')
            );
        }

        try {
            $intent = $this->paymentIntentGateway->retrieve($secretKey, $intentId);
        } catch (ApiErrorException $e) {
            throw new LocalizedException(
                __('We couldn\'t verify your card payment with Stripe. Please try again.')
            );
        }

        if ($intent->status !== 'succeeded') {
            throw new LocalizedException(
                __('Your card payment was not completed. Please try again or use a different payment method.')
            );
        }

        $expectedAmount = (int) round(((float) $order->getBaseGrandTotal()) * 100);
        $expectedCurrency = strtolower((string) $order->getBaseCurrencyCode());
        // The intent id comes from the browser's POST, and status + amount don't prove
        // it was paid for THIS order: an intent from an earlier order at the same total
        // would pass and could be replayed. Intent stamps metadata.quote_id server-side;
        // a quote is deactivated once ordered, so a match means "made for this checkout".
        $intentQuoteId = (string) ($intent->metadata['quote_id'] ?? '');
        if ($intentQuoteId === '' || $intentQuoteId !== (string) $order->getQuoteId()) {
            throw new LocalizedException(
                __('Your card payment could not be verified for this order. Please refresh the page and try again.')
            );
        }

        if ((int) $intent->amount !== $expectedAmount || strtolower((string) $intent->currency) !== $expectedCurrency) {
            throw new LocalizedException(
                __('The payment amount could not be verified for this order. Please contact support before trying again.')
            );
        }

        $payment->setTransactionId($intent->id);
        $payment->setIsTransactionClosed(true);
        $payment->setAdditionalInformation('stripe_payment_intent_status', $intent->status);

        return $this;
    }

    /**
     * A credit memo with "Refund": Stripe gives the amount back to the card
     * (all of it or part, in the base currency the intent was made in).
     *
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $intentId = (string) $payment->getAdditionalInformation('stripe_payment_intent_id');
        $secretKey = $this->stripeConfig->getSecretKey((int) $payment->getOrder()->getStoreId());
        if ($intentId === '' || $secretKey === '') {
            throw new LocalizedException(__('This order has no Stripe payment to refund.'));
        }

        try {
            $minor = (int) round(((float) $amount) * 100);
            $refund = $this->paymentIntentGateway->refund($secretKey, $intentId, $minor);
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Stripe refused the refund: %1', $e->getMessage()));
        }
        if (!in_array($refund->status, ['succeeded', 'pending'], true)) {
            throw new LocalizedException(__('Stripe refused the refund: %1', (string) $refund->status));
        }

        $payment->setTransactionId($refund->id);
        $payment->setParentTransactionId($intentId);
        $payment->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function validate(): self
    {
        return $this;
    }
}
