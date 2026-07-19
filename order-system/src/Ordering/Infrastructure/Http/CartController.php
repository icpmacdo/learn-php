<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Ordering\Application\Command\PutCartLine;
use App\Ordering\Application\Command\PutCartLineHandler;
use App\Ordering\Application\Command\RemoveCartLine;
use App\Ordering\Application\Command\RemoveCartLineHandler;
use App\Ordering\Application\Query\CartLineView;
use App\Ordering\Application\Query\CartView;
use App\Ordering\Application\Query\GetCartViewHandler;
use App\Ordering\Domain\CartCurrencyMismatch;
use App\Ordering\Domain\CartLineNotFound;
use App\Ordering\Domain\TooManyCartLines;
use App\Ordering\Domain\UnknownOrInactiveProduct;
use App\Ordering\Infrastructure\Http\Dto\PutCartLineRequest;
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
 * Cart HTTP edge (customer-scoped: every route requires X-Customer-Id).
 * Thin: resolve the customer, decode+validate the DTO, call the handler,
 * render the view. Domain exceptions become statuses HERE: unknown/inactive
 * product and currency mismatch are 422s (the request names something that
 * cannot join the cart), a missing line on DELETE is a 404.
 */
#[Route('/api/cart')]
final class CartController
{
    private const string SKU_REQUIREMENT = '[A-Z0-9-]{3,32}';

    public function __construct(
        private readonly GetCartViewHandler $view,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_cart_show', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $customerId = CustomerContext::requireCustomer($request);

        return new JsonResponse($this->cartJson($this->view->handle($customerId)));
    }

    #[Route('/lines/{sku}', name: 'api_cart_put_line', requirements: ['sku' => self::SKU_REQUIREMENT], methods: ['PUT'])]
    public function putLine(string $sku, Request $request, PutCartLineHandler $handler): JsonResponse
    {
        $customerId = CustomerContext::requireCustomer($request);

        $dto = PutCartLineRequest::fromArray(JsonBody::decode($request));
        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }
        \assert($dto->quantity !== null); // NotNull validated above

        try {
            $handler->handle(new PutCartLine($customerId->value, $sku, $dto->quantity));
        } catch (UnknownOrInactiveProduct $e) {
            return ApiProblem::validationError('sku', $e->getMessage());
        } catch (CartCurrencyMismatch $e) {
            return ApiProblem::validationError('sku', $e->getMessage());
        } catch (TooManyCartLines $e) {
            return ApiProblem::validationError('sku', $e->getMessage());
        }

        return new JsonResponse($this->cartJson($this->view->handle($customerId)));
    }

    #[Route('/lines/{sku}', name: 'api_cart_remove_line', requirements: ['sku' => self::SKU_REQUIREMENT], methods: ['DELETE'])]
    public function removeLine(string $sku, Request $request, RemoveCartLineHandler $handler): Response
    {
        $customerId = CustomerContext::requireCustomer($request);

        try {
            $handler->handle(new RemoveCartLine($customerId->value, $sku));
        } catch (CartLineNotFound) {
            throw new NotFoundHttpException('No such line in the cart.');
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function cartJson(CartView $view): array
    {
        return [
            'lines' => array_map(
                static fn (CartLineView $line): array => [
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'unitPrice' => self::moneyJson($line->unitPrice),
                    'quantity' => $line->quantity,
                    'lineTotal' => self::moneyJson($line->lineTotal),
                ],
                $view->lines,
            ),
            'total' => $view->total === null ? null : self::moneyJson($view->total),
        ];
    }

    /** @return array{amountMinor: int, currency: string} */
    private static function moneyJson(Money $money): array
    {
        return ['amountMinor' => $money->amountMinor, 'currency' => $money->currency];
    }
}
