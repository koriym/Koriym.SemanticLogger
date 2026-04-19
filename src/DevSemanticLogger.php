<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Profiler\OperationProfile;
use Koriym\SemanticLogger\Profiler\PhpProfile;
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
        // Stop the parent's current segment first, then call inner->open
        // OUTSIDE any active segment so logger/inner bookkeeping is not
        // attributed to either parent or child. Only after that do we start
        // the child's fresh segment.
        if ($this->depth > 0) {
            $this->stopAndAttachToCurrent();
        }

        $id = $this->inner->open($context);

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
        $tracked = isset($this->startedPhp[$openId]);

        if ($tracked) {
            // Stop the being's final segment and capture wallTime BEFORE
            // inner->close runs, so none of the logger plumbing (inner->close,
            // xdebug_stop_trace teardown, json writes, etc.) pollutes this
            // being's profile.
            $this->stopAndAttachTo($openId);
            $this->wallTimes[$openId] = $this->startedPhp[$openId]->stop()->wallTime;
            unset($this->startedPhp[$openId]);
            array_pop($this->openStack);
            $this->depth--;
        }

        $this->inner->close($context, $openId);

        // Returning to a parent operation: resume profiling under the parent.
        if ($tracked && $this->depth > 0) {
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

            $closeWithProfile = $this->attachProfilesToCloseChain($logJson->close, $operations);

            return new LogJson(
                $logJson->schemaUrl,
                $logJson->open,
                $closeWithProfile,
                $logJson->events,
                $logJson->links,
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

    /**
     * Walk the close chain and, at each level, attach the matching OperationProfile
     * directly to that close entry so each being carries its own profile data.
     *
     * @param array<string, OperationProfile> $operations
     */
    private function attachProfilesToCloseChain(EventEntry $close, array $operations): EventEntry
    {
        $nestedClose = $close->close !== null
            ? $this->attachProfilesToCloseChain($close->close, $operations)
            : null;

        $withNested = $close->close === $nestedClose ? $close : $close->withClose($nestedClose);

        if ($close->openId !== null && isset($operations[$close->openId])) {
            return $withNested->withProfile($operations[$close->openId]);
        }

        return $withNested;
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
