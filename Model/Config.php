<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Single reader for the kolai/* configuration section. Values the admin stores
 * encrypted come back decrypted here, so no caller has to know which is which.
 */
class Config
{
    private ScopeConfigInterface $scopeConfig;

    private EncryptorInterface $encryptor;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    public function isEnabled($scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            'kolai/general/enabled',
            ScopeInterface::SCOPE_WEBSITE,
            $scopeCode
        );
    }

    public function isLogEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('kolai/log/enabled');
    }

    /** debug | info | warning | error */
    public function getLogLevel(): string
    {
        return (string) $this->scopeConfig->getValue('kolai/log/level') ?: 'info';
    }

    public function getLogRetentionDays(): int
    {
        return max(0, (int) $this->scopeConfig->getValue('kolai/log/retention_days'));
    }

    /**
     * Rate codes (carrier_method) Kolai may offer. Empty means every active method.
     *
     * @return string[]
     */
    public function getAllowedShippingMethods($scopeCode = null): array
    {
        $stored = $this->value('kolai/shipping/allowed_methods', $scopeCode);

        return $stored === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $stored))));
    }

    /** CMS page identifier, '' when none is chosen. */
    public function getClarificationPage($scopeCode = null): string
    {
        return $this->value('kolai/contract/clarification_page', $scopeCode);
    }

    public function getApiKey($scopeCode = null): string
    {
        return $this->decrypted('kolai/api/api_key', $scopeCode);
    }

    public function getSecretKey($scopeCode = null): string
    {
        return $this->decrypted('kolai/api/secret_key', $scopeCode);
    }

    public function getIyzicoApiKey($scopeCode = null): string
    {
        return $this->decrypted('kolai/iyzico/api_key', $scopeCode);
    }

    public function getIyzicoSecretKey($scopeCode = null): string
    {
        return $this->decrypted('kolai/iyzico/secret_key', $scopeCode);
    }

    public function getIyzicoEnvironment($scopeCode = null): string
    {
        return $this->value('kolai/iyzico/environment', $scopeCode);
    }

    /**
     * Keyed by the library's seller placeholder names, minus the braces, ready
     * to hand to ContractTemplates::render(). Empty settings are dropped so the
     * placeholder survives in the contract instead of leaving a blank line.
     *
     * @return array<string,string>
     */
    public function getSellerSettings($scopeCode = null): array
    {
        $settings = [
            'seller_name' => $this->value('kolai/seller/name', $scopeCode),
            'seller_address' => $this->value('kolai/seller/address', $scopeCode),
            'seller_phone' => $this->value('kolai/seller/phone', $scopeCode),
            'seller_email' => $this->value('kolai/seller/email', $scopeCode),
            'seller_tax_id' => $this->value('kolai/seller/tax_id', $scopeCode),
            'seller_mersis_no' => $this->value('kolai/seller/mersis_no', $scopeCode),
            'delivery_date' => $this->value('kolai/contract/delivery_date', $scopeCode),
            'right_of_withdrawal_period' => $this->value('kolai/contract/right_of_withdrawal_period', $scopeCode),
        ];

        return array_filter($settings, static fn (string $v): bool => $v !== '');
    }

    /**
     * @param string $type ContractTemplates::DISTANCE_SALES | PRELIMINARY_INFO
     * @return string|null null means "use the template bundled with the library"
     */
    public function getContractTemplate(string $type, $scopeCode = null): ?string
    {
        $stored = $this->value('kolai/contract/' . $type, $scopeCode);

        return $stored === '' ? null : $stored;
    }

    private function value(string $path, $scopeCode): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $scopeCode));
    }

    private function decrypted(string $path, $scopeCode): string
    {
        $stored = $this->value($path, $scopeCode);

        return $stored === '' ? '' : trim($this->encryptor->decrypt($stored));
    }
}
