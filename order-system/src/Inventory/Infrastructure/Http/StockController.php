<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Http;

use App\Inventory\Application\Command\SetStockLevel;
use App\Inventory\Application\Command\SetStockLevelHandler;
use App\Inventory\Domain\StockBelowReserved;
use App\Inventory\Domain\StockItem;
use App\Inventory\Domain\StockItemRepository;
use App\Inventory\Infrastructure\Http\Dto\SetStockRequest;
use App\Shared\Domain\Sku;
use App\Shared\Infrastructure\Http\ApiProblem;
use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Inventory HTTP edge (admin-ish: no X-Customer-Id). Note the 409-vs-422
 * choice on PUT: onHand < reserved is a 422 per the PRD's contract table —
 * the request names an impossible level for ANY current reservation state
 * visible to the caller via GET.
 */
#[Route('/api/stock')]
final class StockController
{
    private const string SKU_REQUIREMENT = '[A-Z0-9-]{3,32}';

    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/{sku}', name: 'api_stock_set', requirements: ['sku' => self::SKU_REQUIREMENT], methods: ['PUT'])]
    public function set(string $sku, Request $request, SetStockLevelHandler $handler): JsonResponse
    {
        $dto = SetStockRequest::fromArray(JsonBody::decode($request));

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }
        \assert($dto->onHand !== null); // NotNull validated above

        try {
            $stockItem = $handler->handle(new SetStockLevel($sku, $dto->onHand));
        } catch (StockBelowReserved $e) {
            return ApiProblem::validationError('onHand', $e->getMessage());
        }

        return new JsonResponse($this->stockJson($stockItem));
    }

    #[Route('/{sku}', name: 'api_stock_show', requirements: ['sku' => self::SKU_REQUIREMENT], methods: ['GET'])]
    public function show(string $sku): JsonResponse
    {
        $stockItem = $this->stockItems->bySkuOrNull(Sku::fromString($sku))
            ?? throw new NotFoundHttpException('No stock record for this SKU.');

        return new JsonResponse($this->stockJson($stockItem));
    }

    /** @return array<string, int|string> */
    private function stockJson(StockItem $stockItem): array
    {
        return [
            'sku' => $stockItem->sku()->value,
            'onHand' => $stockItem->onHand(),
            'reserved' => $stockItem->reserved(),
            'available' => $stockItem->available(),
        ];
    }
}
