<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\AddProduct;
use App\Catalog\Application\Command\AddProductHandler;
use App\Catalog\Application\Command\UpdateProduct;
use App\Catalog\Application\Command\UpdateProductHandler;
use App\Catalog\Domain\DuplicateSku;
use App\Catalog\Domain\Product;
use App\Catalog\Domain\ProductNotFound;
use App\Catalog\Domain\ProductRepository;
use App\Catalog\Infrastructure\Http\Dto\CreateProductRequest;
use App\Catalog\Infrastructure\Http\Dto\UpdateProductRequest;
use App\Catalog\Infrastructure\Persistence\ProductListQuery;
use App\Shared\Domain\Sku;
use App\Shared\Infrastructure\Http\ApiProblem;
use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Catalog HTTP edge. Thin: decode -> validate DTO -> call the Application
 * handler -> map the aggregate to JSON. Domain exceptions are translated to
 * statuses HERE (DuplicateSku -> 422, ProductNotFound -> 404) — the domain
 * knows nothing about HTTP.
 *
 * POST and PATCH are admin-ish (seeding/ops): no X-Customer-Id required.
 */
#[Route('/api/products')]
final class ProductController
{
    private const string SKU_REQUIREMENT = '[A-Z0-9-]{3,32}';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductListQuery $listQuery,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_product_create', methods: ['POST'])]
    public function create(Request $request, AddProductHandler $handler): JsonResponse
    {
        $dto = CreateProductRequest::fromArray(JsonBody::decode($request));

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }
        \assert($dto->priceMinor !== null); // NotNull validated above

        try {
            $product = $handler->handle(new AddProduct(
                $dto->sku,
                $dto->name,
                $dto->description,
                $dto->priceMinor,
                $dto->currency,
            ));
        } catch (DuplicateSku $e) {
            return ApiProblem::validationError('sku', $e->getMessage());
        }

        return new JsonResponse($this->productJson($product), Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_product_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = self::positiveIntParam($request, 'page', 1);
        $limit = self::positiveIntParam($request, 'limit', 20);
        if ($page === null) {
            return ApiProblem::validationError('page', 'Page must be a positive integer.');
        }
        if ($limit === null || $limit > 100) {
            return ApiProblem::validationError('limit', 'Limit must be an integer between 1 and 100.');
        }

        $result = $this->listQuery->list($page, $limit);

        return new JsonResponse([
            'products' => $result['products'],
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
            'pages' => (int) ceil($result['total'] / $limit),
        ]);
    }

    #[Route('/{sku}', name: 'api_product_show', requirements: ['sku' => self::SKU_REQUIREMENT], methods: ['GET'])]
    public function show(string $sku): JsonResponse
    {
        $product = $this->products->bySkuOrNull(Sku::fromString($sku))
            ?? throw new NotFoundHttpException('Product not found.');

        return new JsonResponse($this->productJson($product));
    }

    #[Route('/{sku}', name: 'api_product_update', requirements: ['sku' => self::SKU_REQUIREMENT], methods: ['PATCH'])]
    public function update(string $sku, Request $request, UpdateProductHandler $handler): JsonResponse
    {
        $dto = UpdateProductRequest::fromArray(JsonBody::decode($request));

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        try {
            $product = $handler->handle(new UpdateProduct(
                $sku,
                $dto->name,
                $dto->priceMinor,
                $dto->currency,
                $dto->active,
            ));
        } catch (ProductNotFound) {
            throw new NotFoundHttpException('Product not found.');
        }

        return new JsonResponse($this->productJson($product));
    }

    /** @return array<string, mixed> */
    private function productJson(Product $product): array
    {
        return [
            'sku' => $product->sku()->value,
            'name' => $product->name(),
            'description' => $product->description(),
            'price' => [
                'amountMinor' => $product->price()->amountMinor,
                'currency' => $product->price()->currency,
            ],
            'active' => $product->isActive(),
            'createdAt' => $product->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $product->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Query param as a positive int: absent -> default, anything that is not
     * a positive integer string -> null (the caller turns that into a 422).
     */
    private static function positiveIntParam(Request $request, string $name, int $default): ?int
    {
        $raw = $request->query->get($name);
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (preg_match('/^[1-9]\d*$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }
}
