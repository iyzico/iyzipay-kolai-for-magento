<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogLevel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'debug', 'label' => __('Debug (adım adım; üretimde kapatın)')],
            ['value' => 'info', 'label' => __('Info')],
            ['value' => 'warning', 'label' => __('Warning')],
            ['value' => 'error', 'label' => __('Error')],
        ];
    }
}
