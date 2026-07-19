<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Ordering\Application\Command\CancelOrder;
use App\Ordering\Application\Command\CancelOrderHandler;
use App\Ordering\Application\Command\PayOrder;
use App\Ordering\Application\Command\PayOrderHandler;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Command\PlaceOrderHandler;
use App\Ordering\Application\Command\ShipOrder;
use App\Ordering\Application\Command\ShipOrderHandler;
use App\Ordering\Domain\EmptyCart;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\PaymentWasDeclined;
use App\Ordering\Domain\Port\PaymentGatewayTimedOut;
use App\Ordering\Domain\Port\PaymentGatewayUnavailable;
use App\Ordering\Domain\Port\UnrecognizedPaymentMethod;
use App\Ordering\Infrastructure\Http\Dto\PayOrderRequest;
use App\Ordering\Infrastructure\Persistence\OrderHistoryQuery;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\ApiProblem;
use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Order HTTP edge. Customer routes hide existence: another customer's order
 * id answers 404, never 403 (part 2's policy). /ship is admin-ish — no
 * customer header, it exists for ops and tests.
 *
 * Conflict-with-state errors (state machine, refund window, insufficient
 * stock) do NOT appear in catch blocks here: they implement StateConflict
 * and the shared kernel.exception listener renders them as 409 — this
 * controller could not even import Inventory's InsufficientStock legally.
 * What IS caught here is what needs a different status: payment failures
 * (422/502) and not-found (404).
 */
#[Route('/api/orders')]
final class OrderController
{
    private const string UUID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_order_place', methods: ['POST'])]
    public function place(Request $request, PlaceOrderHandler $handler): JsonResponse
    {
        $customerId = CustomerContext::requireCustomer($request);

        try {
            $order = $handler->handle(new PlaceOrder($customerId->value));
        } catch (EmptyCart $e) {
            return ApiProblem::validationError(null, $e->getMessage());
        }
        // InsufficientStock / ProductNoLongerAvailable -> 409 via StateConflict.

        return new JsonResponse($this->orderJson($order), Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_order_history', methods: ['GET'])]
    public function history(Request $request, OrderHistoryQuery $history): JsonResponse
    {
        $customerId = CustomerContext::requireCustomer($request);

        // History reads the read_order_summary projection, never the
        // OrderRepository: flat rows for a flat listing (PRD §7). Detail
        // (below) deliberately reads the write model — not everything is
        // projected.
        return new JsonResponse(['orders' => $history->forCustomer($customerId->value)]);
    }

    #[Route('/{id}', name: 'api_order_show', requirements: ['id' => self::UUID_REQUIREMENT], methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $customerId = CustomerContext::requireCustomer($request);

        $order = $this->orders->byIdOrNull(OrderId::fromString($id));
        if ($order === null || !$order->customerId()->equals($customerId)) {
            throw new NotFoundHttpException('Order not found.');
        }

        return new JsonResponse($this->orderJson($order));
    }

    #[Route('/{id}/payment', name: 'api_order_pay', requirements: ['id' => self::UUID_REQUIREMENT], methods: ['POST'])]
    public function pay(string $id, Request $request, PayOrderHandler $handler): JsonResponse
    {
        $customerId = CustomerContext::requireCustomer($request);

        $dto = PayOrderRequest::fromArray(JsonBody::decode($request));
        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        try {
            $order = $handler->handle(new PayOrder($customerId->value, $id, $dto->paymentMethodToken));
        } catch (OrderNotFound) {
            throw new NotFoundHttpException('Order not found.');
        } catch (PaymentWasDeclined $e) {
            return ApiProblem::validationError(null, $e->getMessage());
        } catch (UnrecognizedPaymentMethod $e) {
            return ApiProblem::validationError('paymentMethodToken', $e->getMessage());
        } catch (PaymentGatewayTimedOut|PaymentGatewayUnavailable $e) {
            // The order is untouched: still placed, still payable, stock
            // still reserved. 502: the failure is upstream, retry is safe.
            return ApiProblem::badGateway($e->getMessage());
        }
        // IllegalOrderTransition (not placed) -> 409 via StateConflict.

        return new JsonResponse($this->orderJson($order));
    }

    #[Route('/{id}/cancel', name: 'api_order_cancel', requirements: ['id' => self::UUID_REQUIREMENT], methods: ['POST'])]
    public function cancel(string $id, Request $request, CancelOrderHandler $handler): JsonResponse
    {
        $customerId = CustomerContext::requireCustomer($request);

        try {
            $order = $handler->handle(new CancelOrder($customerId->value, $id));
        } catch (OrderNotFound) {
            throw new NotFoundHttpException('Order not found.');
        }
        // IllegalOrderTransition / RefundWindowClosed -> 409 via StateConflict.

        return new JsonResponse($this->orderJson($order));
    }

    #[Route('/{id}/ship', name: 'api_order_ship', requirements: ['id' => self::UUID_REQUIREMENT], methods: ['POST'])]
    public function ship(string $id, ShipOrderHandler $handler): JsonResponse
    {
        // Admin-ish: no customer header, no ownership check.
        try {
            $order = $handler->handle(new ShipOrder($id));
        } catch (OrderNotFound) {
            throw new NotFoundHttpException('Order not found.');
        }
        // IllegalOrderTransition (not paid) -> 409 via StateConflict.

        return new JsonResponse($this->orderJson($order));
    }

    /** @return array<string, mixed> */
    private function orderJson(Order $order): array
    {
        return [
            'id' => $order->id()->value,
            'status' => $order->status()->value,
            'currency' => $order->currency(),
            'lines' => array_map(
                static fn (OrderLine $line): array => [
                    'sku' => $line->sku()->value,
                    'name' => $line->name(),
                    'unitPrice' => self::moneyJson($line->unitPrice()),
                    'quantity' => $line->quantity()->value,
                    'lineTotal' => self::moneyJson($line->lineTotal()),
                ],
                $order->lines(),
            ),
            'total' => self::moneyJson($order->total()),
            'placedAt' => $order->placedAt()->format(\DateTimeInterface::ATOM),
            'paidAt' => $order->paidAt()?->format(\DateTimeInterface::ATOM),
            'shippedAt' => $order->shippedAt()?->format(\DateTimeInterface::ATOM),
            'cancelledAt' => $order->cancelledAt()?->format(\DateTimeInterface::ATOM),
            'transactionId' => $order->transactionId(),
        ];
    }

    /** @return array{amountMinor: int, currency: string} */
    private static function moneyJson(Money $money): array
    {
        return ['amountMinor' => $money->amountMinor, 'currency' => $money->currency];
    }
}
