<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Profiler\PhpProfile;
use Koriym\SemanticLogger\Profiler\Profile;
use Koriym\SemanticLogger\Profiler\XdebugTrace;
use Override;

final class DevSemanticLogger implements SemanticLoggerInterface
{
    /** @var array<string, PhpProfile> */
    private array $started = [];

    /** @var array<string, float> */
    private array $wallTimes = [];
    private XdebugTrace|null $xdebug = null;
    private int $depth = 0;

    public function __construct(
        private readonly SemanticLoggerInterface $inner,
    ) {
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $id = $this->inner->open($context);
        $this->started[$id] = PhpProfile::start();

        if ($this->depth === 0) {
            $this->xdebug = XdebugTrace::start();
        }

        $this->depth++;

        return $id;
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        $this->inner->close($context, $openId);
        $this->depth--;

        if (isset($this->started[$openId])) {
            $this->wallTimes[$openId] = $this->started[$openId]->stop()->wallTime;
            unset($this->started[$openId]);
        }

        if ($this->depth === 0 && $this->xdebug !== null) {
            $this->xdebug = $this->xdebug->stop();
        }
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        $this->inner->event($context);
    }

    /** @param list<array{rel: string, href: string, title?: string, type?: string}> $links */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        $logJson = $this->inner->flush($links);
        $profile = new Profile(xdebug: $this->xdebug, operationWallTimes: $this->wallTimes);

        $this->wallTimes = [];
        $this->xdebug = null;

        return new LogJson(
            $logJson->schemaUrl,
            $logJson->open,
            $logJson->close,
            $logJson->events,
            $logJson->links,
            $profile,
        );
    }
}
