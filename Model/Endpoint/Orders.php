<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Endpoint;

use Iyzico\Kolai\Model\Api\ApiRequest;
use Iyzico\Kolai\Model\Api\Result;
use Iyzico\Kolai\Model\Order\OrderCreator;
use Iyzico\Kolai\Model\Order\OrderFormatter;
use Iyzico\Kolai\Model\Order\OrderUpdater;
use Iyzico\Kolai\Model\Order\StatusMap;
use Iyzico\Kolai\Model\Payment\PaymentMethod;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * GET /order-types, POST /orders, GET|PATCH /orders/{id}.
 *
 * Reads and updates are limited to orders placed through Kolai (payment method
 * "kolai"): a signature for Kolai must not let it read or re-status any other
 * order in the store.
 */
class Orders
{
    private StatusMap $statusMap;

    private OrderCreator $creator;

    private OrderUpdater $updater;

    private OrderFormatter $formatter;

    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        StatusMap $statusMap,
        OrderCreator $creator,
        OrderUpdater $updater,
        OrderFormatter $formatter,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->statusMap       = $statusMap;
        $this->creator         = $creator;
        $this->updater         = $updater;
        $this->formatter       = $formatter;
        $this->orderRepository = $orderRepository;
    }

    public function types(ApiRequest $request): Result
    {
        return new Result($this->statusMap->statuses());
    }

    /**
     * @throws KolaiException
     */
    public function create(ApiRequest $request): Result
    {
        $order = $this->creator->create($request->body());

        return new Result($this->formatter->summary($order));
    }

    /**
     * @throws KolaiException
     */
    public function get(ApiRequest $request): Result
    {
        return new Result($this->formatter->detail($this->load($request->param('id'))));
    }

    /**
     * @throws KolaiException
     */
    public function update(ApiRequest $request): Result
    {
        $order = $this->updater->update($this->load($request->param('id')), $request->body());

        return new Result($this->formatter->detail($order));
    }

    /**
     * @throws KolaiException
     */
    private function load(string $id): Order
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw Errors::notFound('Order not found');
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get((int) $id);
        } catch (NoSuchEntityException $e) {
            throw Errors::notFound('Order not found');
        }

        if (!$order->getPayment() || $order->getPayment()->getMethod() !== PaymentMethod::CODE) {
            throw Errors::notFound('Order not found');
        }

        return $order;
    }
}
