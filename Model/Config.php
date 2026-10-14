<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Stripe settings (Stores > Configuration > Sales > Payment Methods >
 * Stripe): test/live mode and the matching key pair. Secret keys are
 * stored encrypted (`backend_model` Encrypted) and decrypted here.
 */
class Config
{
    private const PREFIX = 'payment/zero_stripe/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isTestMode(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'test_mode', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Decrypted secret key for the active mode, '' when not configured.
     */
    public function getSecretKey(int $storeId): string
    {
        $encrypted = $this->getValue(($this->isTestMode($storeId) ? 'test' : 'live') . '_secret_key', $storeId);

        return $encrypted !== '' ? $this->encryptor->decrypt($encrypted) : '';
    }

    /**
     * Publishable (browser-safe) key for the active mode, '' when not configured.
     */
    public function getPublishableKey(int $storeId): string
    {
        return $this->getValue(($this->isTestMode($storeId) ? 'test' : 'live') . '_publishable_key', $storeId);
    }

    private function getValue(string $field, int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
