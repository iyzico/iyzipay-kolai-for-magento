<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Order;

use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;

/**
 * Order statuses as Kolai sees them, and the Magento state each belongs to.
 *
 * WooCommerce has statuses only; Magento has states with statuses assigned to
 * them. Kolai sends a status, so the state is derived: the status's own state,
 * preferring the order's current state when the status is assigned to several.
 */
class StatusMap
{
    private OrderConfig $orderConfig;

    private StatusCollectionFactory $statusCollectionFactory;

    /** @var array<string,array<int,string>>|null status => states, default state first */
    private ?array $states = null;

    public function __construct(OrderConfig $orderConfig, StatusCollectionFactory $statusCollectionFactory)
    {
        $this->orderConfig             = $orderConfig;
        $this->statusCollectionFactory = $statusCollectionFactory;
    }

    /** @return array<string,string> status code => label */
    public function statuses(): array
    {
        $statuses = [];
        foreach ($this->orderConfig->getStatuses() as $code => $label) {
            $statuses[(string) $code] = (string) $label;
        }

        return $statuses;
    }

    public function isKnown(string $status): bool
    {
        return isset($this->statuses()[$status]);
    }

    /** '' when the status is assigned to no state. */
    public function stateFor(string $status, string $currentState = ''): string
    {
        $states = $this->stateMap()[$status] ?? [];
        if ($states === []) {
            return '';
        }

        return in_array($currentState, $states, true) ? $currentState : $states[0];
    }

    /** @return array<string,array<int,string>> */
    private function stateMap(): array
    {
        if ($this->states !== null) {
            return $this->states;
        }

        $this->states = [];
        foreach ($this->statusCollectionFactory->create()->joinStates() as $row) {
            $status = (string) $row->getData('status');
            $state  = (string) $row->getData('state');
            if ($state === '') {
                continue;
            }
            $this->states[$status] = $this->states[$status] ?? [];
            if ((int) $row->getData('is_default') === 1) {
                array_unshift($this->states[$status], $state);
            } else {
                $this->states[$status][] = $state;
            }
        }

        return $this->states;
    }
}
