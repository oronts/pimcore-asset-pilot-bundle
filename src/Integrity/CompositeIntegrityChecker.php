<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Integrity;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

/**
 * Picks the highest-priority tagged checker whose supports() matches the asset, the same
 * resolve-by-priority pattern the bundle uses for strategies and filters. resolve() exposes that
 * checker so a heal can reuse it to test historical version binaries via checkBinary().
 */
class CompositeIntegrityChecker
{
    /** @var list<IntegrityCheckerInterface> highest priority first */
    private array $checkers;

    /**
     * @param iterable<IntegrityCheckerInterface> $checkers
     */
    public function __construct(iterable $checkers)
    {
        $list = $checkers instanceof \Traversable ? iterator_to_array($checkers, false) : array_values($checkers);

        $indexed = [];
        foreach ($list as $index => $checker) {
            $indexed[] = ['index' => $index, 'checker' => $checker];
        }
        usort(
            $indexed,
            static fn (array $a, array $b): int => [$b['checker']->priority(), $a['index']] <=> [$a['checker']->priority(), $b['index']],
        );

        $this->checkers = array_map(static fn (array $entry): IntegrityCheckerInterface => $entry['checker'], $indexed);
    }

    public function resolve(Asset $asset): ?IntegrityCheckerInterface
    {
        foreach ($this->checkers as $checker) {
            if ($checker->supports($asset)) {
                return $checker;
            }
        }

        return null;
    }

    public function check(Asset $asset): IntegrityResult
    {
        return $this->resolve($asset)?->check($asset)
            ?? new IntegrityResult(IntegrityStatus::Unverifiable, 'none', 'No integrity checker supports this asset.');
    }
}
