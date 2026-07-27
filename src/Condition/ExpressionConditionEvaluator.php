<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Condition;

use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Support\Regex;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\ParsedExpression;

class ExpressionConditionEvaluator implements ConditionEvaluatorInterface
{
    private ?ExpressionLanguage $expressionLanguage = null;

    /** @var array<string, \Symfony\Component\ExpressionLanguage\ParsedExpression> */
    protected array $compiledCache = [];

    /**
     * @param iterable<ExpressionFunctionProviderInterface> $functionProviders consumer-tagged providers
     */
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly iterable $functionProviders = [],
    ) {}

    public function evaluate(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        try {
            return $this->evaluateStrict($object, $asset, $rule, $locale);
        } catch (\Throwable $e) {
            $this->logger->warning('Condition evaluation failed for rule "{rule}": {error}', [
                'rule' => $rule->name,
                'condition' => $rule->condition,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    public function evaluateStrict(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        if ($rule->condition === null || $rule->condition === '') {
            return true;
        }

        $result = (bool) $this->getExpressionLanguage()->evaluate(
            $this->getCompiledExpression($rule->condition),
            [
                'object' => $object,
                'asset' => $asset,
                'rule' => $rule,
                'locale' => $locale,
            ],
        );

        $this->logger->debug('Condition "{condition}" evaluated to {result} for rule "{rule}".', [
            'condition' => $rule->condition,
            'result' => $result ? 'true' : 'false',
            'rule' => $rule->name,
        ]);

        return $result;
    }

    /**
     * Parse-only check used by config validation. Uses the same configured ExpressionLanguage as
     * evaluate(), so a condition calling the bundle's own functions (is_image, asset_type, ...) is
     * not falsely reported as a syntax error. Throws on invalid syntax.
     */
    public function validateSyntax(string $expression): void
    {
        $parsed = $this->getExpressionLanguage()->parse($expression, ['object', 'asset', 'rule', 'locale']);
        $this->validateLiteralRegexPatterns($parsed);
    }

    protected function getCompiledExpression(string $expression): \Symfony\Component\ExpressionLanguage\ParsedExpression
    {
        if (!isset($this->compiledCache[$expression])) {
            $this->compiledCache[$expression] = $this->getExpressionLanguage()->parse(
                $expression,
                ['object', 'asset', 'rule', 'locale'],
            );
        }

        return $this->compiledCache[$expression];
    }

    protected function getExpressionLanguage(): ExpressionLanguage
    {
        if ($this->expressionLanguage !== null) {
            return $this->expressionLanguage;
        }

        $this->expressionLanguage = new ExpressionLanguage();
        $this->registerFunctions();

        foreach ($this->functionProviders as $provider) {
            $this->expressionLanguage->registerProvider($provider);
        }

        return $this->expressionLanguage;
    }

    protected function registerFunctions(): void
    {
        $el = $this->expressionLanguage;

        $el->register(
            'asset_type',
            static fn (string $asset): string => sprintf('(%s)->getType()', $asset),
            static fn (array $vars, Asset $asset): string => $asset->getType(),
        );

        $el->register(
            'asset_size',
            static fn (string $asset): string => sprintf('(%s)->getFileSize()', $asset),
            static fn (array $vars, Asset $asset): int => (int) $asset->getFileSize(),
        );

        $el->register(
            'asset_extension',
            static fn (string $asset): string => sprintf('pathinfo((%s)->getFilename(), PATHINFO_EXTENSION)', $asset),
            static function (array $vars, Asset $asset): string {
                return strtolower(pathinfo($asset->getFilename(), PATHINFO_EXTENSION));
            },
        );

        $el->register(
            'object_class',
            static fn (string $object): string => sprintf('(%s)->getClassName()', $object),
            static fn (array $vars, AbstractObject $object): string => $object instanceof Concrete ? ($object->getClassName() ?? '') : '',
        );

        $el->register(
            'has_property',
            static fn (string $element, string $name): string => sprintf('(%s)->getProperty(%s) !== null', $element, $name),
            static function (array $vars, Asset|AbstractObject $element, string $name): bool {
                return $element->getProperty($name) !== null;
            },
        );

        $el->register(
            'path_matches',
            static fn (string $asset, string $pattern): string => sprintf('%s::matches(%s, (%s)->getFullPath())', Regex::class, $pattern, $asset),
            static function (array $vars, Asset $asset, string $pattern): bool {
                return Regex::matches($pattern, $asset->getFullPath());
            },
        );

        $el->register(
            'is_image',
            static fn (string $asset): string => sprintf('(%s)->getType() === "image"', $asset),
            static fn (array $vars, Asset $asset): bool => $asset->getType() === 'image',
        );

        $el->register(
            'is_video',
            static fn (string $asset): string => sprintf('(%s)->getType() === "video"', $asset),
            static fn (array $vars, Asset $asset): bool => $asset->getType() === 'video',
        );

        $el->register(
            'is_document',
            static fn (string $asset): string => sprintf('(%s)->getType() === "document"', $asset),
            static fn (array $vars, Asset $asset): bool => $asset->getType() === 'document',
        );
    }

    private function validateLiteralRegexPatterns(ParsedExpression $expression): void
    {
        $this->walkExpressionNodes($expression->getNodes());
    }

    private function walkExpressionNodes(Node $node): void
    {
        if ($node instanceof FunctionNode && $node->attributes['name'] === 'path_matches') {
            $arguments = array_values($node->nodes['arguments']->nodes);
            $pattern = $arguments[1] ?? null;
            if ($pattern instanceof ConstantNode && is_string($pattern->attributes['value'])) {
                Regex::assertValid($pattern->attributes['value']);
            }
        }

        foreach ($node->nodes as $child) {
            $this->walkExpressionNodes($child);
        }
    }
}
