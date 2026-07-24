<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

/**
 * Thrown when a rule's target-path template cannot be rendered at runtime. The pipeline fails closed
 * on this rather than substituting a generic fallback destination that would silently misfile the
 * asset. The empty-segment fallback for a *successful* render still lives in the resolver.
 */
class PathResolutionException extends \RuntimeException {}
