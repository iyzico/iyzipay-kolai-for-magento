<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    // ponytail: literals instead of Kolai\Payment\IyzicoCredentials::ENV_* so the
    // admin page renders even before composer require iyzico/kolai-php. The library
    // treats anything but "production" as sandbox, so a drift here cannot go live.
    public function toOptionArray(): array
    {
        return [
            ['value' => 'sandbox', 'label' => __('Sandbox')],
            ['value' => 'production', 'label' => __('Canlı')],
        ];
    }
}
