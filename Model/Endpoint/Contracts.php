<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Endpoint;

use Iyzico\Kolai\Model\Api\ApiRequest;
use Iyzico\Kolai\Model\Api\Result;
use Iyzico\Kolai\Model\Config;
use Kolai\Contract\ContractTemplates;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Kolai\Exception\NotFoundException;
use Magento\Cms\Helper\Page as PageHelper;
use Magento\Cms\Model\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * POST /contracts and GET /contracts/clarification-text.
 *
 * Seller placeholders are filled from the Kolai settings; buyer and order
 * placeholders stay in the text for Kolai to fill at checkout.
 */
class Contracts
{
    private Config $config;

    private PageFactory $pageFactory;

    private PageHelper $pageHelper;

    private StoreManagerInterface $storeManager;

    public function __construct(
        Config $config,
        PageFactory $pageFactory,
        PageHelper $pageHelper,
        StoreManagerInterface $storeManager
    ) {
        $this->config       = $config;
        $this->pageFactory  = $pageFactory;
        $this->pageHelper   = $pageHelper;
        $this->storeManager = $storeManager;
    }

    /**
     * Body is optional and ignored: both documents always come back together.
     *
     * @throws KolaiException
     */
    public function all(ApiRequest $request): Result
    {
        $settings  = $this->config->getSellerSettings();
        $contracts = [];

        foreach (ContractTemplates::types() as $type => $title) {
            $rendered         = ContractTemplates::render($type, $this->config->getContractTemplate($type), $settings);
            $contracts[$type] = ['title' => $title] + $rendered;
        }

        try {
            $contracts['clarificationText'] = $this->clarificationLink();
        } catch (NotFoundException $e) {
            $contracts['clarificationText'] = null;
        }

        return new Result($contracts);
    }

    /**
     * @throws KolaiException
     */
    public function clarificationText(ApiRequest $request): Result
    {
        return new Result($this->clarificationLink());
    }

    /**
     * @return array{pageId:int,title:string,url:string}
     * @throws NotFoundException
     */
    private function clarificationLink(): array
    {
        $pageId = (int) $this->config->getClarificationPage();
        if ($pageId <= 0) {
            throw Errors::notFound('Clarification text page is not configured');
        }

        $page = $this->pageFactory->create()->setStoreId((int) $this->storeManager->getStore()->getId())->load($pageId);
        if (!$page->getId() || !$page->isActive()) {
            throw Errors::notFound('Clarification text page not found');
        }

        $url = (string) $this->pageHelper->getPageUrl($pageId);
        if ($url === '') {
            // getPageUrl returns null when the page is not assigned to this store.
            throw Errors::notFound('Clarification text page not found');
        }

        return [
            'pageId' => (int) $page->getId(),
            'title'  => (string) $page->getTitle(),
            'url'    => $url,
        ];
    }
}
