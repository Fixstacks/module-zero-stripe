<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Zero\Stripe\Model\Config;

class ConfigTest extends TestCase
{
    private const VALUES = [
        ['payment/zero_stripe/test_secret_key', ScopeInterface::SCOPE_STORE, 2, 'enc:test'],
        ['payment/zero_stripe/live_secret_key', ScopeInterface::SCOPE_STORE, 2, 'enc:live'],
        ['payment/zero_stripe/test_publishable_key', ScopeInterface::SCOPE_STORE, 2, 'pk_test'],
        ['payment/zero_stripe/live_publishable_key', ScopeInterface::SCOPE_STORE, 2, 'pk_live'],
    ];

    public function testTestModeUsesDecryptedTestKeys(): void
    {
        $config = $this->config(true, self::VALUES);

        $this->assertTrue($config->isTestMode(2));
        $this->assertSame('sk_dec(enc:test)', $config->getSecretKey(2));
        $this->assertSame('pk_test', $config->getPublishableKey(2));
    }

    public function testLiveModeUsesLiveKeys(): void
    {
        $config = $this->config(false, self::VALUES);

        $this->assertSame('sk_dec(enc:live)', $config->getSecretKey(2));
        $this->assertSame('pk_live', $config->getPublishableKey(2));
    }

    public function testMissingKeysAreEmptyAndNotDecrypted(): void
    {
        $config = $this->config(true, []);

        $this->assertSame('', $config->getSecretKey(2));
        $this->assertSame('', $config->getPublishableKey(2));
    }

    /**
     * @param array<int, array<int, mixed>> $values
     */
    private function config(bool $testMode, array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnMap([
            ['payment/zero_stripe/test_mode', ScopeInterface::SCOPE_STORE, 2, $testMode],
        ]);
        $scopeConfig->method('getValue')->willReturnMap($values);

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($values ? $this->atMost(1) : $this->never())->method('decrypt')
            ->willReturnCallback(static fn (string $v): string => "sk_dec($v)");

        return new Config($scopeConfig, $encryptor);
    }
}
