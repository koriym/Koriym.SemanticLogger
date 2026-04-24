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

/**
 * @psalm-import-type EventEntryList from Types
 * @psalm-import-type OperationProfilesById from Types
 * @psalm-import-type SchemaLinks from Types
 * @psalm-import-type WallTimesByOperationId from Types
 * @psalm-import-type XdebugSegmentsByOperationId from Types
 * @psalm-import-type XhprofSegmentsByOperationId from Types
 */
final class DevSemanticLogger implements SemanticLoggerInterface
{
    /** @var array<string, PhpProfile> */
    private array $startedPhp = [];

    /** @var WallTimesByOperationId */
    private array $wallTimes = [];

    /** @var XdebugSegmentsByOperationId */
    private array $xdebugSegments = [];

    /** @var XhprofSegmentsByOperationId */
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

    /** @param SchemaLinks $links */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        try {
            $logJson = $this->inner->flush($links);

            $operations = [];
            foreach ($this->wallTimes as $id => $wallTime) {
                $profile = new OperationProfile(
                    wallTime: $wallTime,
                    xdebugTrace: $this->xdebugSegments[$id] ?? [],
                    xhprofProfile: $this->xhprofSegments[$id] ?? [],
                );

                if (! $profile->hasProfilerData()) {
                    continue;
                }

                $operations[$id] = $profile;
            }

            $closeWithProfile = $this->attachProfilesToCloses($logJson->close, $operations);

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
     * Walk the close tree and, at each node, attach the matching OperationProfile
     * directly to that close entry when external profiler output was captured.
     *
     * @param EventEntryList        $closes
     * @param OperationProfilesById $operations
     *
     * @return EventEntryList
     */
    private function attachProfilesToCloses(array $closes, array $operations): array
    {
        $result = [];
        foreach ($closes as $close) {
            $withNested = $close->withClose($this->attachProfilesToCloses($close->close, $operations));

            if ($close->openId !== null && isset($operations[$close->openId])) {
                $result[] = $withNested->withProfile($operations[$close->openId]);

                continue;
            }

            $result[] = $withNested;
        }

        return $result;
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
