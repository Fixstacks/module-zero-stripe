<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\ViewModel;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\Checkout\ViewModel\Checkout\CheckoutPage;
use Zero\Stripe\Model\Config;

/**
 * What Zero Checkout's Stripe blocks need: whether to render at all, and
 * the publishable (browser-safe) key. The secret key never leaves
 * Model\Config / the server.
 */
class CheckoutConfig implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutPage $checkoutPage,
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config
    ) {
    }

    /**
     * Publishable key when Stripe is offered on this checkout, null otherwise.
     *
     * @return string|null
     */
    public function getPublishableKey(): ?string
    {
        if (!$this->checkoutPage->isPaymentMethodAvailable('zero_stripe')) {
            return null;
        }
        $key = $this->config->getPublishableKey((int) $this->checkoutSession->getQuote()->getStoreId());

        return $key !== '' ? $key : null;
    }
}
