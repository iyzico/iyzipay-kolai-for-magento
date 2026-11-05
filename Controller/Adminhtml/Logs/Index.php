<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Controller\Adminhtml\Logs;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * System > Kolai > Kolai Logları. Filtering and paging are plain query
 * parameters read by the block, so a filtered view is a shareable URL.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iyzico_Kolai::logs';

    private PageFactory $pageFactory;

    public function __construct(Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Iyzico_Kolai::logs');
        $page->getConfig()->getTitle()->prepend(__('Kolai Logları'));

        return $page;
    }
}
