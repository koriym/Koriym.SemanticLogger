<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Profiler\OperationProfile;
use Koriym\SemanticLogger\Profiler\PhpProfile;
use Koriym\SemanticLogger\Profiler\Profile;
use Koriym\SemanticLogger\Profiler\XdebugTrace;
use Koriym\SemanticLogger\Profiler\XHProfResult;
use Override;

use function array_pop;
use function end;
use function uniqid;

final class DevSemanticLogger implements SemanticLoggerInterface
{
    /** @var array<string, PhpProfile> */
    private array $startedPhp = [];

    /** @var array<string, float> */
    private array $wallTimes = [];

    /** @var array<string, list<XdebugTrace>> */
    private array $xdebugSegments = [];

    /** @var array<string, list<XHProfResult>> */
    private array $xhprofSegments = [];

    /** @var list<string> */
    private array $openStack = [];
    private XdebugTrace|null $activeXdebug = null;
    private XHProfResult|null $activeXhprof = null;
    private int $depth = 0;

    public function __construct(
        private readonly SemanticLoggerInterface $inner,
    ) {
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $id = $this->inner->open($context);

        // Before entering a child operation, stop the parent's current segment
        // and attribute it to the parent. This gives each being its own
        // per-segment trace/xhprof instead of one global trace for the whole run.
        if ($this->depth > 0) {
            $this->stopAndAttachToCurrent();
        }

        $this->openStack[] = $id;
        $this->startedPhp[$id] = PhpProfile::start();
        $this->xdebugSegments[$id] = [];
        $this->xhprofSegments[$id] = [];
        $this->startNewSegment();
        $this->depth++;

        return $id;
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        $this->inner->close($context, $openId);

        if (! isset($this->startedPhp[$openId])) {
            return;
        }

        // Close out this being's final segment before popping it off the stack.
        $this->stopAndAttachTo($openId);

        $this->wallTimes[$openId] = $this->startedPhp[$openId]->stop()->wallTime;
        unset($this->startedPhp[$openId]);
        array_pop($this->openStack);
        $this->depth--;

        // Returning to a parent operation: resume profiling under the parent.
        if ($this->depth > 0) {
            $this->startNewSegment();
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
        try {
            $logJson = $this->inner->flush($links);

            $operations = [];
            foreach ($this->wallTimes as $id => $wallTime) {
                $operations[$id] = new OperationProfile(
                    wallTime: $wallTime,
                    xdebug: $this->xdebugSegments[$id] ?? [],
                    xhprof: $this->xhprofSegments[$id] ?? [],
                );
            }

            $profile = new Profile(operations: $operations);

            return new LogJson(
                $logJson->schemaUrl,
                $logJson->open,
                $logJson->close,
                $logJson->events,
                $logJson->links,
                $profile,
            );
        } finally {
            // Best-effort cleanup: stop anything still running so it does not
            // leak into the next request. Xdebug/XHProf can be start/stop'd
            // repeatedly, but leaving either active would break the next run.
            if ($this->activeXdebug !== null) {
                $this->activeXdebug->stop();
            }

            if ($this->activeXhprof !== null) {
                $this->activeXhprof->stop(uniqid('dev_flush_', true));
            }

            $this->startedPhp = [];
            $this->wallTimes = [];
            $this->xdebugSegments = [];
            $this->xhprofSegments = [];
            $this->openStack = [];
            $this->activeXdebug = null;
            $this->activeXhprof = null;
            $this->depth = 0;
        }
    }

    private function startNewSegment(): void
    {
        $this->activeXdebug = XdebugTrace::start();
        $this->activeXhprof = XHProfResult::start();
    }

    private function stopAndAttachToCurrent(): void
    {
        $currentId = end($this->openStack);
        if ($currentId === false) {
            return;
        }

        $this->stopAndAttachTo($currentId);
    }

    private function stopAndAttachTo(string $openId): void
    {
        if ($this->activeXdebug !== null) {
            $this->xdebugSegments[$openId][] = $this->activeXdebug->stop();
            $this->activeXdebug = null;
        }

        if ($this->activeXhprof !== null) {
            // XHProfResult::stop hashes this string into the output filename;
            // segments within the same second would otherwise collide.
            $this->xhprofSegments[$openId][] = $this->activeXhprof->stop(uniqid($openId . '_', true));
            $this->activeXhprof = null;
        }
    }
}
