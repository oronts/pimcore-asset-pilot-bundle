<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\DependencyInjection;

use Oronts\AssetPilotBundle\Action\RuleActionDeliveryContextInterface;
use Oronts\AssetPilotBundle\Action\RuleActionInterface;
use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Audit\AuditExportInterface;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Audit\AuditRetentionInterface;
use Oronts\AssetPilotBundle\Audit\AuditWriterInterface;
use Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface;
use Oronts\AssetPilotBundle\Condition\ExpressionConditionEvaluator;
use Oronts\AssetPilotBundle\DependencyInjection\OrontsAssetPilotExtension;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Enum\NotificationSeverity;
use Oronts\AssetPilotBundle\Filter\AssetFilterInterface;
use Oronts\AssetPilotBundle\Filter\CompositeFilter;
use Oronts\AssetPilotBundle\Health\HealthChecker;
use Oronts\AssetPilotBundle\Health\HealthCheckerInterface;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerResolverInterface;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Naming\SafeNamingStrategy;
use Oronts\AssetPilotBundle\Notification\Notification;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcher;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcherInterface;
use Oronts\AssetPilotBundle\PathResolver\PathResolverInterface;
use Oronts\AssetPilotBundle\PathResolver\TemplatePathResolver;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanClaimStoreInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolverInterface;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Oronts\AssetPilotBundle\Service\AssetIntegrityServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMetadataMutationService;
use Oronts\AssetPilotBundle\Service\AssetMetadataMutationServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use Oronts\AssetPilotBundle\Service\AssetPropertyServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizerInterface;
use Oronts\AssetPilotBundle\Service\AssetSearchService;
use Oronts\AssetPilotBundle\Service\AssetSearchServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetZipService;
use Oronts\AssetPilotBundle\Service\AssetZipServiceInterface;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
use Oronts\AssetPilotBundle\Service\ConfidenceScorerInterface;
use Oronts\AssetPilotBundle\Service\ConfigValidator;
use Oronts\AssetPilotBundle\Service\ConfigValidatorInterface;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\ContentUsageScannerInterface;
use Oronts\AssetPilotBundle\Service\DbalApplyPlanClaimStore;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjection;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjectionFreshness;
use Oronts\AssetPilotBundle\Service\DependencyProjectionFreshnessInterface;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use Oronts\AssetPilotBundle\Service\DependencyProjectionRebuilder;
use Oronts\AssetPilotBundle\Service\DependencyProjectionRebuilderInterface;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifier;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifierInterface;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointer;
use Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointerInterface;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepServiceInterface;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Service\FailureReplayServiceInterface;
use Oronts\AssetPilotBundle\Service\IntegrityHealHistoryService;
use Oronts\AssetPilotBundle\Service\IntegrityHealHistoryServiceInterface;
use Oronts\AssetPilotBundle\Service\LocationDriftService;
use Oronts\AssetPilotBundle\Service\LocationDriftServiceInterface;
use Oronts\AssetPilotBundle\Service\MetricsService;
use Oronts\AssetPilotBundle\Service\MetricsServiceInterface;
use Oronts\AssetPilotBundle\Service\MovePlanner;
use Oronts\AssetPilotBundle\Service\MovePlannerInterface;
use Oronts\AssetPilotBundle\Service\NormalizeFilenamesService;
use Oronts\AssetPilotBundle\Service\NormalizeFilenamesServiceInterface;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrain;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrainInterface;
use Oronts\AssetPilotBundle\Service\OperationDeliveryDispatcher;
use Oronts\AssetPilotBundle\Service\OperationDeliveryDispatcherInterface;
use Oronts\AssetPilotBundle\Service\OperationDeliveryProcessor;
use Oronts\AssetPilotBundle\Service\OperationDeliveryProcessorInterface;
use Oronts\AssetPilotBundle\Service\OperationDeliveryRetryCoordinator;
use Oronts\AssetPilotBundle\Service\OperationDeliveryRetryCoordinatorInterface;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStore;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStoreInterface;
use Oronts\AssetPilotBundle\Service\OperationJournal;
use Oronts\AssetPilotBundle\Service\OperationJournalInterface;
use Oronts\AssetPilotBundle\Service\OperationRecoveryCoordinator;
use Oronts\AssetPilotBundle\Service\OperationRecoveryCoordinatorInterface;
use Oronts\AssetPilotBundle\Service\OperationReverter;
use Oronts\AssetPilotBundle\Service\OperationReverterInterface;
use Oronts\AssetPilotBundle\Service\OperationRunExecutor;
use Oronts\AssetPilotBundle\Service\OperationRunExecutorInterface;
use Oronts\AssetPilotBundle\Service\OperationRunRetention;
use Oronts\AssetPilotBundle\Service\OperationRunRetentionInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStore;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Oronts\AssetPilotBundle\Service\PrometheusFormatter;
use Oronts\AssetPilotBundle\Service\PrometheusFormatterInterface;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Service\QuarantineServiceInterface;
use Oronts\AssetPilotBundle\Service\Query\VisibleObjectSelector;
use Oronts\AssetPilotBundle\Service\Query\VisibleObjectSelectorInterface;
use Oronts\AssetPilotBundle\Service\ReviewedAssetLockCoordinator;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationService;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Oronts\AssetPilotBundle\Service\RuleOverlapAnalyzer;
use Oronts\AssetPilotBundle\Service\RuleOverlapAnalyzerInterface;
use Oronts\AssetPilotBundle\Service\RulePortability;
use Oronts\AssetPilotBundle\Service\RulePortabilityInterface;
use Oronts\AssetPilotBundle\Service\RulePreviewPlanService;
use Oronts\AssetPilotBundle\Service\RulePreviewPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\StorageTrendService;
use Oronts\AssetPilotBundle\Service\StorageTrendServiceInterface;
use Oronts\AssetPilotBundle\Service\UndoHealEligibilityProbeInterface;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinder;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealerInterface;
use Oronts\AssetPilotBundle\Service\ZipDownloadTokenStore;
use Oronts\AssetPilotBundle\Service\ZipDownloadTokenStoreInterface;
use Oronts\AssetPilotBundle\Tests\Unit\Merge\MergeContextStub;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipBuildResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(OrontsAssetPilotExtension::class)]
final class ExtensionContractTest extends TestCase
{
    #[Test]
    public function reviewedMutationServicesRequireTheInternalLockCoordinator(): void
    {
        $container = new ContainerBuilder();
        (new OrontsAssetPilotExtension())->load([], $container);

        self::assertTrue((new \ReflectionClass(ReviewedAssetLockCoordinator::class))->isFinal());
        self::assertTrue($container->hasDefinition(ReviewedAssetLockCoordinator::class));
        self::assertTrue($container->getDefinition(ReviewedAssetLockCoordinator::class)->isAutowired());

        foreach ([
            QuarantineService::class,
            UnusedAssetFinder::class,
            VersionRollbackHealer::class,
            AssetMetadataMutationService::class,
            EmptyFolderSweepService::class,
        ] as $service) {
            $constructor = (new \ReflectionClass($service))->getConstructor();
            self::assertNotNull($constructor);
            $parameters = array_filter(
                $constructor->getParameters(),
                static fn (\ReflectionParameter $parameter): bool => $parameter->getType() instanceof \ReflectionNamedType
                    && $parameter->getType()->getName() === ReviewedAssetLockCoordinator::class,
            );
            self::assertCount(1, $parameters, sprintf('%s must require exactly one reviewed lock coordinator.', $service));
            self::assertFalse(array_values($parameters)[0]->isDefaultValueAvailable());
        }
    }

    #[Test]
    public function everyDocumentedCoreAliasResolvesToItsPublishedDefault(): void
    {
        $container = new ContainerBuilder();
        (new OrontsAssetPilotExtension())->load([], $container);

        foreach (self::documentedAliases() as $interface => $implementation) {
            self::assertTrue($container->hasAlias($interface), sprintf('%s is documented as replaceable.', $interface));
            self::assertSame($implementation, (string) $container->getAlias($interface));
        }

        $documentation = file_get_contents(dirname(__DIR__, 3) . '/docs/overriding.md');
        self::assertIsString($documentation);
        preg_match_all('/^\| `([^`]+Interface)` \| `([^`]+)` \|$/m', $documentation, $matches, PREG_SET_ORDER);

        $documentedRows = [];
        foreach ($matches as [, $interface, $implementation]) {
            $documentedRows[$interface] = $implementation;
        }

        $expectedRows = [];
        foreach (self::documentedAliases() as $interface => $implementation) {
            $expectedRows[self::shortName($interface)] = self::shortName($implementation);
        }

        self::assertSame($expectedRows, $documentedRows, 'The public overriding table and service aliases must change together.');
    }

    #[Test]
    public function productionConsumersUsePublishedInterfacesInsteadOfConcreteDefaults(): void
    {
        $source = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $source,
            \FilesystemIterator::SKIP_DOTS,
        ));
        $violations = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (!is_string($code)) {
                throw new \RuntimeException(sprintf('Could not read %s.', $file->getPathname()));
            }
            foreach (self::documentedAliases() as $implementation) {
                $pattern = '/\\b' . preg_quote(self::shortName($implementation), '/') . '\\s+\\$/';
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = sprintf('%s injects %s directly.', $file->getPathname(), $implementation);
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function consumerCanReplaceAndDecorateAFacadeAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register(ExampleAssetZipService::class)->setPublic(true);
        $container->setAlias(AssetZipServiceInterface::class, ExampleAssetZipService::class)->setPublic(true);
        $container->register(DecoratingAssetZipService::class)
            ->setDecoratedService(AssetZipServiceInterface::class)
            ->setArguments([new Reference(DecoratingAssetZipService::class . '.inner')])
            ->setPublic(true);
        $container->compile();

        $service = $container->get(AssetZipServiceInterface::class);

        self::assertInstanceOf(DecoratingAssetZipService::class, $service);
        self::assertEquals(
            new ZipBuildResult('/tmp/example.zip', 2, 2, 0),
            $service->buildFromAssetIds([10, 20]),
        );
    }

    #[Test]
    public function documentedPathAndConditionContractsAreExecutable(): void
    {
        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);
        $object->method('getKey')->willReturn('sku-42');
        $object->method('getProperty')->with('workflow_state')->willReturn('ready');
        $rule = Rule::fromConfig('workflow', [
            'class' => 'Product',
            'condition' => 'ready',
            'target_path' => '/Configured',
            'strategy' => 'always',
        ]);
        $resolver = new ExamplePathResolver();
        $conditions = new ExampleConditionEvaluator();

        $resolver->validateTemplate('/Products');
        $conditions->validateSyntax('ready');

        self::assertSame('/Resolved/sku-42', $resolver->resolve($object, $asset, $rule));
        self::assertTrue($conditions->evaluate($object, $asset, $rule));
        $documentation = file_get_contents(dirname(__DIR__, 3) . '/docs/extending.md');
        self::assertIsString($documentation);
        self::assertStringContainsString('public function validateTemplate(string $template): void', $documentation);
        self::assertStringContainsString('public function validateSyntax(string $expression): void', $documentation);
    }

    #[Test]
    public function consumerCanReplaceAndDecorateIntegritySelectionPolicy(): void
    {
        $container = new ContainerBuilder();
        $container->register(ExampleIntegrityCheckerResolver::class)->setPublic(true);
        $container->setAlias(IntegrityCheckerResolverInterface::class, ExampleIntegrityCheckerResolver::class)->setPublic(true);
        $container->register(DecoratingIntegrityCheckerResolver::class)
            ->setDecoratedService(IntegrityCheckerResolverInterface::class)
            ->setArguments([new Reference(DecoratingIntegrityCheckerResolver::class . '.inner')])
            ->setPublic(true);
        $container->compile();

        $resolver = $container->get(IntegrityCheckerResolverInterface::class);
        $asset = $this->createMock(Asset::class);

        self::assertInstanceOf(DecoratingIntegrityCheckerResolver::class, $resolver);
        self::assertSame('consumer', $resolver->resolve($asset)?->check($asset)->checker);
        self::assertSame('consumer', $resolver->check($asset)->checker);
        self::assertSame(1, $resolver->checks);
    }

    #[Test]
    public function consumerCanReplaceHealthPolicyAndDecorateNotificationFanOut(): void
    {
        $container = new ContainerBuilder();
        $container->register(ExampleHealthChecker::class)->setPublic(true);
        $container->setAlias(HealthCheckerInterface::class, ExampleHealthChecker::class)->setPublic(true);
        $container->register(ExampleNotificationDispatcher::class)->setPublic(true);
        $container->setAlias(NotificationDispatcherInterface::class, ExampleNotificationDispatcher::class)->setPublic(true);
        $container->register(DecoratingNotificationDispatcher::class)
            ->setDecoratedService(NotificationDispatcherInterface::class)
            ->setArguments([new Reference(DecoratingNotificationDispatcher::class . '.inner')])
            ->setPublic(true);
        $container->compile();

        $health = $container->get(HealthCheckerInterface::class);
        $dispatcher = $container->get(NotificationDispatcherInterface::class);
        $notification = new Notification('tenant.alert', NotificationSeverity::Warning, 'Tenant alert', 'Review it.');

        self::assertInstanceOf(ExampleHealthChecker::class, $health);
        self::assertSame(HealthStatus::Warning, $health->overall($health->run()));
        self::assertInstanceOf(DecoratingNotificationDispatcher::class, $dispatcher);
        $dispatcher->dispatch($notification);
        self::assertSame($notification, $dispatcher->received);
    }

    #[Test]
    public function documentedRuleActionContractIsExecutable(): void
    {
        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);
        $delivery = new ExampleDeliveryContext('delivery-42');
        $action = new ExampleRuleAction();

        self::assertSame('assign_review_tag', $action->getType());
        $payload = $action->prepare($asset, $object, ['tag' => 'legal-review']);
        $action->applyPrepared($asset, $payload, $delivery);

        self::assertSame('delivery-42', $action->handledDeliveryId);
        self::assertSame('legal-review', $action->handledTag);
        self::assertSame(1, $delivery->heartbeats);
    }

    #[Test]
    public function documentedDuplicateStrategyContractIsExecutable(): void
    {
        $strategy = new ExampleDuplicateMergeStrategy();

        self::assertSame('tag_for_review', $strategy->name());
        self::assertTrue($strategy->repointsReferences());
        self::assertSame(
            DispositionOutcome::LeftReferenced,
            $strategy->disposeCopy(new RepointReport(12, 10, 0, ['a reference remains']), new MergeContextStub(12))->outcome,
        );
        self::assertSame(
            DispositionOutcome::Skipped,
            $strategy->disposeCopy(new RepointReport(12, 10, 3, []), new MergeContextStub(12))->outcome,
        );
    }

    /** @return array<class-string, class-string> */
    private static function documentedAliases(): array
    {
        return [
            RuleEngineInterface::class => RuleEngine::class,
            PathResolverInterface::class => TemplatePathResolver::class,
            ConditionEvaluatorInterface::class => ExpressionConditionEvaluator::class,
            NamingStrategyInterface::class => SafeNamingStrategy::class,
            ElementAuthorizationInterface::class => ElementAuthorization::class,
            AssetFilterInterface::class => CompositeFilter::class,
            AssetFieldExtractorInterface::class => AssetFieldExtractor::class,
            AssetDependencyResolverInterface::class => AssetDependencyResolver::class,
            AssetDependencyTargetExtractorInterface::class => AssetDependencyTargetExtractor::class,
            UnusedAssetFinderInterface::class => UnusedAssetFinder::class,
            AssetSearchServiceInterface::class => AssetSearchService::class,
            ConfidenceScorerInterface::class => ConfidenceScorer::class,
            AssetIntegrityServiceInterface::class => AssetIntegrityService::class,
            IntegrityCheckerResolverInterface::class => CompositeIntegrityChecker::class,
            HealthCheckerInterface::class => HealthChecker::class,
            NotificationDispatcherInterface::class => NotificationDispatcher::class,
            AssetMetadataMutationServiceInterface::class => AssetMetadataMutationService::class,
            AssetPropertyServiceInterface::class => AssetPropertyService::class,
            AssetReorganizerInterface::class => AssetReorganizer::class,
            ConfigValidatorInterface::class => ConfigValidator::class,
            ContentUsageScannerInterface::class => ContentUsageScanner::class,
            DuplicateDetectionServiceInterface::class => DuplicateDetectionService::class,
            DuplicateMergeServiceInterface::class => DuplicateMergeService::class,
            DuplicateReferenceRepointerInterface::class => DuplicateReferenceRepointer::class,
            EmptyFolderSweepServiceInterface::class => EmptyFolderSweepService::class,
            FailureReplayServiceInterface::class => FailureReplayService::class,
            IntegrityHealHistoryServiceInterface::class => IntegrityHealHistoryService::class,
            LocationDriftServiceInterface::class => LocationDriftService::class,
            VisibleObjectSelectorInterface::class => VisibleObjectSelector::class,
            MetricsServiceInterface::class => MetricsService::class,
            NormalizeFilenamesServiceInterface::class => NormalizeFilenamesService::class,
            ObjectSaveDrainInterface::class => ObjectSaveDrain::class,
            OperationReverterInterface::class => OperationReverter::class,
            PrometheusFormatterInterface::class => PrometheusFormatter::class,
            QuarantineServiceInterface::class => QuarantineService::class,
            RuleOverlapAnalyzerInterface::class => RuleOverlapAnalyzer::class,
            RulePortabilityInterface::class => RulePortability::class,
            StorageTrendServiceInterface::class => StorageTrendService::class,
            AuditWriterInterface::class => AuditLogger::class,
            AuditQueryInterface::class => AuditLogger::class,
            AuditExportInterface::class => AuditLogger::class,
            AuditRetentionInterface::class => AuditLogger::class,
            OperationJournalInterface::class => OperationJournal::class,
            ApplyPlanClaimStoreInterface::class => DbalApplyPlanClaimStore::class,
            OperationDeliveryStoreInterface::class => OperationDeliveryStore::class,
            OperationDeliveryProcessorInterface::class => OperationDeliveryProcessor::class,
            OperationDeliveryDispatcherInterface::class => OperationDeliveryDispatcher::class,
            AssetOrganizerInterface::class => AssetOrganizer::class,
            MovePlannerInterface::class => MovePlanner::class,
            OrganizeDispatcherInterface::class => OrganizeDispatcher::class,
            AssetZipServiceInterface::class => AssetZipService::class,
            ApplyPlanServiceInterface::class => ApplyPlanService::class,
            RulePreviewPlanServiceInterface::class => RulePreviewPlanService::class,
            ZipDownloadTokenStoreInterface::class => ZipDownloadTokenStore::class,
            OperationRunStoreInterface::class => OperationRunStore::class,
            OperationRunExecutorInterface::class => OperationRunExecutor::class,
            OperationRunRetentionInterface::class => OperationRunRetention::class,
            ReviewedObjectOperationServiceInterface::class => ReviewedObjectOperationService::class,
            OperationRecoveryCoordinatorInterface::class => OperationRecoveryCoordinator::class,
            OperationDeliveryRetryCoordinatorInterface::class => OperationDeliveryRetryCoordinator::class,
            DependencyProjectionInterface::class => DbalDependencyProjection::class,
            DependencyProjectionFreshnessInterface::class => DbalDependencyProjectionFreshness::class,
            DependencyProjectionRebuilderInterface::class => DependencyProjectionRebuilder::class,
            DependencyUsageVerifierInterface::class => DependencyUsageVerifier::class,
            UndoHealEligibilityProbeInterface::class => VersionRollbackHealer::class,
            VersionRollbackHealerInterface::class => VersionRollbackHealer::class,
            ApiDateFormatterInterface::class => ApiDateFormatter::class,
        ];
    }

    private static function shortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}

final class ExamplePathResolver implements PathResolverInterface
{
    public function resolve(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): string
    {
        return '/Resolved/' . $object->getKey();
    }

    public function validateTemplate(string $template): void
    {
        if ($template === '' || !str_starts_with($template, '/')) {
            throw new \InvalidArgumentException('Paths must be absolute.');
        }
    }
}

final class ExampleConditionEvaluator implements ConditionEvaluatorInterface
{
    public function evaluate(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        try {
            return $this->evaluateStrict($object, $asset, $rule, $locale);
        } catch (\Throwable) {
            return false;
        }
    }

    public function evaluateStrict(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        return $rule->condition === null || $object->getProperty('workflow_state') === $rule->condition;
    }

    public function validateSyntax(string $expression): void
    {
        if (trim($expression) === '') {
            throw new \InvalidArgumentException('Conditions cannot be empty.');
        }
    }
}

final class ExampleAssetZipService implements AssetZipServiceInterface
{
    public function buildFromAssetIds(array $assetIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        return new ZipBuildResult($assetIds === [] ? null : '/tmp/example.zip', count($assetIds), count($assetIds), 0);
    }

    public function buildFromFolder(int $folderId, bool $recursive = true, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        return new ZipBuildResult('/tmp/example.zip', 1, 1, 0);
    }

    public function buildFromObjects(array $objectIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        return new ZipBuildResult($objectIds === [] ? null : '/tmp/example.zip', count($objectIds), count($objectIds), 0);
    }
}

final readonly class DecoratingAssetZipService implements AssetZipServiceInterface
{
    public function __construct(private AssetZipServiceInterface $inner) {}

    public function buildFromAssetIds(array $assetIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        return $this->inner->buildFromAssetIds($assetIds, $options, $actor);
    }

    public function buildFromFolder(int $folderId, bool $recursive = true, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        return $this->inner->buildFromFolder($folderId, $recursive, $options, $actor);
    }

    public function buildFromObjects(array $objectIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult
    {
        return $this->inner->buildFromObjects($objectIds, $options, $actor);
    }
}

final class ExampleIntegrityChecker implements IntegrityCheckerInterface
{
    public function priority(): int
    {
        return 100;
    }

    public function supports(Asset $asset): bool
    {
        return true;
    }

    public function check(Asset $asset): IntegrityResult
    {
        return new IntegrityResult(IntegrityStatus::Renderable, 'consumer');
    }

    public function checkBinary(string $binary, string $extension): IntegrityResult
    {
        return new IntegrityResult(IntegrityStatus::Renderable, 'consumer');
    }
}

final class ExampleIntegrityCheckerResolver implements IntegrityCheckerResolverInterface
{
    private readonly IntegrityCheckerInterface $checker;

    public function __construct()
    {
        $this->checker = new ExampleIntegrityChecker();
    }

    public function resolve(Asset $asset): ?IntegrityCheckerInterface
    {
        return $this->checker;
    }

    public function check(Asset $asset): IntegrityResult
    {
        return $this->checker->check($asset);
    }
}

final class DecoratingIntegrityCheckerResolver implements IntegrityCheckerResolverInterface
{
    public int $checks = 0;

    public function __construct(private readonly IntegrityCheckerResolverInterface $inner) {}

    public function resolve(Asset $asset): ?IntegrityCheckerInterface
    {
        return $this->inner->resolve($asset);
    }

    public function check(Asset $asset): IntegrityResult
    {
        ++$this->checks;

        return $this->inner->check($asset);
    }
}

final class ExampleHealthChecker implements HealthCheckerInterface
{
    public function run(): array
    {
        return [new HealthCheckResult('tenant-policy', HealthStatus::Warning, 'Review required.')];
    }

    public function overall(array $results): HealthStatus
    {
        return $results[0]->status ?? HealthStatus::Ok;
    }
}

final class ExampleNotificationDispatcher implements NotificationDispatcherInterface
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function dispatch(Notification $notification): void {}
}

final class DecoratingNotificationDispatcher implements NotificationDispatcherInterface
{
    public ?Notification $received = null;

    public function __construct(private readonly NotificationDispatcherInterface $inner) {}

    public function isEnabled(): bool
    {
        return $this->inner->isEnabled();
    }

    public function dispatch(Notification $notification): void
    {
        $this->received = $notification;
        $this->inner->dispatch($notification);
    }
}

final class ExampleRuleAction implements RuleActionInterface
{
    public ?string $handledDeliveryId = null;
    public ?string $handledTag = null;

    public function getType(): string
    {
        return 'assign_review_tag';
    }

    public function prepare(Asset $asset, AbstractObject $object, array $config): array
    {
        return ['tag' => (string) ($config['tag'] ?? 'review')];
    }

    public function applyPrepared(Asset $asset, array $payload, RuleActionDeliveryContextInterface $delivery): void
    {
        $this->handledDeliveryId = $delivery->deliveryId();
        $this->handledTag = (string) $payload['tag'];
        $delivery->heartbeat();
    }
}

final class ExampleDeliveryContext implements RuleActionDeliveryContextInterface
{
    public int $heartbeats = 0;

    public function __construct(private readonly string $id) {}

    public function deliveryId(): string
    {
        return $this->id;
    }

    public function attempt(): int
    {
        return 1;
    }

    public function heartbeat(): void
    {
        ++$this->heartbeats;
    }
}

final class ExampleDuplicateMergeStrategy implements DuplicateMergeStrategyInterface
{
    public function name(): string
    {
        return 'tag_for_review';
    }

    public function repointsReferences(): bool
    {
        return true;
    }

    public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
    {
        $copyId = $context->copyId();
        if (!$report->fullyRepointed) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, 'references remain');
        }

        return new CopyDisposition($copyId, DispositionOutcome::Skipped, 'tagged for review, left in place');
    }
}
