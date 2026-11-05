<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Config\Source;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * CMS pages keyed by page_id. Magento's own source keys by identifier, which
 * is not unique across store views — and the endpoint has to return pageId.
 */
class CmsPage implements OptionSourceInterface
{
    private CollectionFactory $collectionFactory;

    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }

    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- Seçilmedi --')]];
        foreach ($this->collectionFactory->create()->addOrder('title', 'ASC') as $page) {
            $options[] = [
                'value' => (string) $page->getId(),
                'label' => sprintf('%s (%s)', $page->getTitle(), $page->getIdentifier()),
            ];
        }

        return $options;
    }
}
