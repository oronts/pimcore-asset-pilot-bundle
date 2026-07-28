<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use OpenApi\Generator;
use Oronts\AssetPilotBundle\Controller\Api\OpenApiSpecification;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

#[CoversClass(OpenApiSpecification::class)]
class OpenApiSpecificationTest extends TestCase
{
    private static ?array $document = null;

    #[Test]
    public function exposesEveryAssetPilotRouteToStudioOpenApi(): void
    {
        $document = $this->document();
        $operations = iterator_count($this->operations($document));

        self::assertSame(57, count($document['paths']));
        self::assertSame(58, $operations);
        self::assertArrayHasKey('{prefix}/asset-pilot/health', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/health/readiness', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/assets/{id}/lock', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/assets/download-zip/prepare', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/assets/download-zip/{token}', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/integrity/history', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/operations/runs', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/operations/runs/{id}', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/operations/recovery', $document['paths']);
        self::assertArrayHasKey('{prefix}/asset-pilot/operations/deliveries/retry', $document['paths']);
    }

    #[Test]
    public function reflectsEveryControllerRouteAsExactlyOneOpenApiOperation(): void
    {
        $routePairs = [];
        foreach ($this->apiControllerClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $route = $attribute->newInstance();
                    $path = $route->path;
                    self::assertIsString($path, $class . '::' . $method->getName() . ' must declare a single string route path.');
                    self::assertNotEmpty($route->methods, $class . '::' . $method->getName() . ' must declare its HTTP methods.');
                    foreach ($route->methods as $httpMethod) {
                        $routePairs[] = '{prefix}/asset-pilot' . $path . ' ' . strtolower((string) $httpMethod);
                    }
                }
            }
        }
        sort($routePairs);

        $httpMethods = ['get', 'post', 'put', 'patch', 'delete'];
        $openApiPairs = [];
        foreach ($this->document()['paths'] as $path => $item) {
            foreach (array_keys($item) as $key) {
                if (in_array($key, $httpMethods, true)) {
                    $openApiPairs[] = $path . ' ' . $key;
                }
            }
        }
        sort($openApiPairs);

        self::assertSame($routePairs, $openApiPairs);
    }

    /** @return list<class-string> */
    private function apiControllerClasses(): array
    {
        $root = realpath(__DIR__ . '/../../../src/Controller/Api');
        self::assertIsString($root, 'API controller directory not found');

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr((string) $file->getPathname(), strlen($root) + 1, -4);
            $class = 'Oronts\\AssetPilotBundle\\Controller\\Api\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    #[Test]
    public function declaresAJsonRequestBodyForEveryBodyBasedPostOperation(): void
    {
        $bodyless = [
            'asset_pilot_lock_asset',
            'asset_pilot_audit_revert',
            'asset_pilot_quarantine_restore',
            'asset_pilot_operation_run_cancel',
            'asset_pilot_operation_run_retry',
        ];

        foreach ($this->operations($this->document()) as [$method, $operation]) {
            if ($method !== 'post' || in_array($operation['operationId'], $bodyless, true)) {
                continue;
            }

            self::assertArrayHasKey('requestBody', $operation, $operation['operationId']);
            self::assertArrayHasKey('application/json', $operation['requestBody']['content'], $operation['operationId']);
        }
    }

    #[Test]
    public function declaresEveryPathTemplateVariableAsARequiredPathParameter(): void
    {
        $document = $this->document();

        foreach ($document['paths'] as $path => $pathItem) {
            preg_match_all('/\{([^}]+)\}/', $path, $matches);
            foreach ($this->operationsForPath($pathItem) as $operation) {
                $parameters = array_map(
                    fn (array $parameter): array => $this->resolveParameter($document, $parameter),
                    [...($pathItem['parameters'] ?? []), ...($operation['parameters'] ?? [])],
                );

                foreach ($matches[1] as $variable) {
                    $matching = array_values(array_filter(
                        $parameters,
                        static fn (array $parameter): bool => ($parameter['in'] ?? null) === 'path' && ($parameter['name'] ?? null) === $variable,
                    ));

                    self::assertCount(1, $matching, sprintf('%s must declare path parameter %s.', $operation['operationId'], $variable));
                    self::assertTrue($matching[0]['required'] ?? false, sprintf('%s path parameter %s must be required.', $operation['operationId'], $variable));
                }
            }
        }
    }

    #[Test]
    public function declaresANonSuccessResponseForEveryOperation(): void
    {
        foreach ($this->operations($this->document()) as [, $operation]) {
            $errorResponses = array_filter(
                array_keys($operation['responses']),
                static fn (int|string $status): bool => !str_starts_with((string) $status, '2'),
            );

            self::assertNotEmpty($errorResponses, $operation['operationId']);
        }
    }

    #[Test]
    public function publishesReusableSchemasAndParameters(): void
    {
        $components = $this->document()['components'];

        foreach (['Error', 'Pagination', 'PositiveId', 'IdList', 'MoveOperationPreview', 'OperationResult', 'OperationRun', 'OperationRunSummary', 'OperationRunItem', 'OperationRecoveryResult', 'DeadOperationDelivery', 'DeliveryRetryReview', 'AuditEntry', 'IntegrityHealHistoryItem'] as $schema) {
            self::assertArrayHasKey($schema, $components['schemas']);
        }
        foreach (['StudioPrefix', 'Page', 'Limit', 'Sort', 'Order', 'RuleName', 'AssetId', 'OperationRunId'] as $parameter) {
            self::assertArrayHasKey($parameter, $components['parameters']);
        }
    }

    #[Test]
    public function reflectsPaginatedTagsAndSignedRulePlans(): void
    {
        $document = $this->document();
        $tagsSchema = $document['paths']['{prefix}/asset-pilot/assets/tags']['get']['responses'][200]['content']['application/json']['schema'];
        $tagProperties = $tagsSchema['properties'];

        self::assertSame(['items', 'total', 'page', 'limit', 'pages'], array_keys($tagProperties));

        $previewSchema = $document['paths']['{prefix}/asset-pilot/rules/{name}/preview']['get']['responses'][200]['content']['application/json']['schema'];
        self::assertSame(['operations', 'planToken'], $previewSchema['required']);

        $apply = $document['paths']['{prefix}/asset-pilot/rules/{name}/apply']['post'];
        $applySchema = $apply['requestBody']['content']['application/json']['schema'];
        self::assertSame(['objectId', 'planToken'], $applySchema['required']);
        self::assertArrayHasKey(409, $apply['responses']);

        foreach (['bulk-delete', 'bulk-move', 'bulk-quarantine'] as $action) {
            $operation = $document['paths']['{prefix}/asset-pilot/unused-assets/' . $action]['post'];
            $requestProperties = $operation['requestBody']['content']['application/json']['schema']['properties'];
            self::assertArrayHasKey('dryRun', $requestProperties);
            self::assertArrayHasKey('planToken', $requestProperties);

            $responseSchema = $operation['responses'][200]['content']['application/json']['schema'];
            self::assertContains('dryRun', $responseSchema['required']);
            self::assertContains('planToken', $responseSchema['required']);
            self::assertContains('eligible', $responseSchema['required']);
            self::assertArrayHasKey(409, $operation['responses']);
        }

        foreach (['organize' => 1, 'organize/bulk' => 0] as $path => $previewIndex) {
            $operation = $document['paths']['{prefix}/asset-pilot/' . $path]['post'];
            $requestProperties = $operation['requestBody']['content']['application/json']['schema']['properties'];
            self::assertArrayHasKey('dryRun', $requestProperties);
            self::assertArrayHasKey('planToken', $requestProperties);
            $previewSchema = $operation['responses'][200]['content']['application/json']['schema']['oneOf'][$previewIndex];
            self::assertContains('planToken', $previewSchema['required']);
            self::assertArrayHasKey(409, $operation['responses']);
        }

        $integrityHeal = $document['paths']['{prefix}/asset-pilot/integrity/heal']['post'];
        $integrityRequest = $integrityHeal['requestBody']['content']['application/json']['schema']['properties'];
        self::assertArrayHasKey('dryRun', $integrityRequest);
        self::assertArrayHasKey('planToken', $integrityRequest);
        $integrityResponse = $integrityHeal['responses'][200]['content']['application/json']['schema'];
        self::assertContains('planToken', $integrityResponse['required']);
        self::assertArrayHasKey(409, $integrityHeal['responses']);

        self::assertArrayHasKey('objectId', $document['components']['schemas']['MoveOperationPreview']['properties']);

        $cancelResponses = $document['paths']['{prefix}/asset-pilot/operations/runs/{id}/cancel']['post']['responses'];
        self::assertArrayHasKey(200, $cancelResponses);
        self::assertArrayHasKey(202, $cancelResponses);
        $retryStatusUrl = $document['paths']['{prefix}/asset-pilot/operations/runs/{id}/retry']['post']['responses'][202]['content']['application/json']['schema']['properties']['statusUrl'];
        self::assertSame('^/', $retryStatusUrl['pattern']);
        $retryResponses = $document['paths']['{prefix}/asset-pilot/operations/runs/{id}/retry']['post']['responses'];
        self::assertArrayHasKey(200, $retryResponses);
        self::assertContains('completed', $retryResponses[200]['content']['application/json']['schema']['properties']['status']['enum']);

        $runSummary = $document['components']['schemas']['OperationRunSummary'];
        self::assertSame(array_column(OperationRunKind::cases(), 'value'), $runSummary['properties']['kind']['enum']);
        self::assertContains('blocked', $runSummary['properties']['status']['enum']);
        self::assertContains('blockedCount', $runSummary['required']);
        $runItem = $document['components']['schemas']['OperationRunItem'];
        self::assertContains('blocked', $runItem['properties']['status']['enum']);
        self::assertContains('state', $runItem['required']);

        $merge = $document['paths']['{prefix}/asset-pilot/duplicates/merge']['post'];
        $mergeRequests = $merge['requestBody']['content']['application/json']['schema']['oneOf'];
        self::assertSame(['checksum'], $mergeRequests[0]['required']);
        self::assertSame(['runId'], $mergeRequests[1]['required']);
        $mergeResponse = $merge['responses'][200]['content']['application/json']['schema'];
        foreach (['runId', 'status', 'statusUrl'] as $runtimeField) {
            self::assertContains($runtimeField, $mergeResponse['required']);
        }

        $unusedParameters = array_map(
            fn (array $parameter): array => $this->resolveParameter($document, $parameter),
            $document['paths']['{prefix}/asset-pilot/unused-assets']['get']['parameters'],
        );
        $unusedParameterNames = array_column($unusedParameters, 'name');
        self::assertNotContains('minSize', $unusedParameterNames);
        self::assertNotContains('maxSize', $unusedParameterNames);
    }

    #[Test]
    public function publishesTheSignedDeadDeliveryRetryContract(): void
    {
        $document = $this->document();
        $operation = $document['paths']['{prefix}/asset-pilot/operations/deliveries/retry']['post'];
        $request = $operation['requestBody']['content']['application/json']['schema'];

        self::assertSame(1, $request['properties']['limit']['minimum']);
        self::assertSame(1_000, $request['properties']['limit']['maximum']);
        self::assertArrayHasKey('planToken', $request['properties']);
        self::assertArrayHasKey(400, $operation['responses']);
        self::assertArrayHasKey(409, $operation['responses']);

        $response = $operation['responses'][200]['content']['application/json']['schema'];
        self::assertSame('#/components/schemas/DeliveryRetryReview', $response['$ref']);
        $review = $document['components']['schemas']['DeliveryRetryReview'];
        self::assertSame(['applied', 'planToken', 'count', 'deliveries'], $review['required']);
        self::assertSame(
            '#/components/schemas/DeadOperationDelivery',
            $review['properties']['deliveries']['items']['$ref'],
        );
    }

    #[Test]
    public function excludesTheRetiredActionFailureStatus(): void
    {
        $document = $this->document();

        foreach (['OperationResult', 'AuditEntry'] as $schema) {
            $statuses = $document['components']['schemas'][$schema]['properties']['status']['enum'];
            self::assertNotContains('action_failed', $statuses);
            self::assertContains('completed_with_observer_error', $statuses);
        }

        foreach (['audit', 'audit/export'] as $path) {
            $parameters = $document['paths']['{prefix}/asset-pilot/' . $path]['get']['parameters'];
            $status = array_find($parameters, static fn (array $parameter): bool => ($parameter['name'] ?? null) === 'status');
            self::assertNotContains('action_failed', $status['schema']['enum']);
        }
    }

    #[Test]
    public function generatesAValidOpenApiDocument(): void
    {
        $root = dirname(__DIR__, 3);
        $specification = Generator::scan([
            $root . '/src/Controller/Api/OpenApiSpecification.php',
            $root . '/src/Controller/Api/OpenApiPaths.php',
            $root . '/tests/Fixtures/OpenApiTestInfo.php',
        ]);

        self::assertTrue($specification->validate());
    }

    private function document(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }

        $root = dirname(__DIR__, 3);
        $specification = Generator::scan([
            $root . '/src/Controller/Api/OpenApiSpecification.php',
            $root . '/src/Controller/Api/OpenApiPaths.php',
            $root . '/tests/Fixtures/OpenApiTestInfo.php',
        ]);
        self::$document = json_decode($specification->toJson(), true, flags: JSON_THROW_ON_ERROR);

        return self::$document;
    }

    /** @return \Generator<int, array{string, array<string, mixed>}> */
    private function operations(array $document): \Generator
    {
        foreach ($document['paths'] as $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (isset($pathItem[$method])) {
                    yield [$method, $pathItem[$method]];
                }
            }
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function operationsForPath(array $pathItem): \Generator
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($pathItem[$method])) {
                yield $pathItem[$method];
            }
        }
    }

    private function resolveParameter(array $document, array $parameter): array
    {
        if (!isset($parameter['$ref'])) {
            return $parameter;
        }

        $name = basename($parameter['$ref']);

        return $document['components']['parameters'][$name];
    }
}
