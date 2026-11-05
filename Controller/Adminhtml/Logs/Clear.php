<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Controller\Adminhtml\Logs;

use Iyzico\Kolai\Model\Log\LogRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Empties kolai_log. POST only; the backend's form-key check guards it.
 */
class Clear extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iyzico_Kolai::logs';

    private LogRepository $logs;

    public function __construct(Context $context, LogRepository $logs)
    {
        parent::__construct($context);
        $this->logs = $logs;
    }

    public function execute(): Redirect
    {
        try {
            $this->logs->clear();
            $this->messageManager->addSuccessMessage(__('Tüm Kolai logları silindi.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Loglar silinemedi: %1', $e->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('kolai/logs/index');
    }
}
