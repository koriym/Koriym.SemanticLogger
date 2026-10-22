<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Type definitions for SemanticLogger
 *
 * @phpcs:disable SlevomatCodingStandard.Commenting.DocCommentSpacing
 * @psalm-suppress UnusedClass
 *
 * Core scalar and map types
 * @psalm-type ContextData = array<string, mixed>
 * @psalm-type JsonMap = array<string, mixed>
 * @psalm-type SchemaUrl = string
 * @psalm-type LogType = string
 * @psalm-type OperationId = string
 * @psalm-type RelationType = string
 *
 * Link types
 * @psalm-type SchemaLink = array{
 *     rel: RelationType,
 *     href: SchemaUrl,
 *     title?: string,
 *     type?: string
 * }
 * @psalm-type SchemaLinks = list<SchemaLink>
 *
 * Profiling types
 * @psalm-type ProfileArtifact = array{path: string}
 * @psalm-type OperationProfileData = array{
 *     wallTime: float,
 *     xdebugTrace?: list<ProfileArtifact>,
 *     xhprofProfile?: list<ProfileArtifact>
 * }
 * @psalm-type WallTimesByOperationId = array<OperationId, float>
 * @psalm-type XdebugSegmentsByOperationId = array<OperationId, list<Profiler\XdebugTrace>>
 * @psalm-type XhprofSegmentsByOperationId = array<OperationId, list<Profiler\XHProfResult>>
 * @psalm-type OperationProfilesById = array<OperationId, Profiler\OperationProfile>
 *
 * Runtime collection types
 * @psalm-type LogRecord = OpenEntry|EventEntry|CloseEntry
 * @psalm-type LogRecordList = list<LogRecord>
 * @psalm-type ScopedRecordList = list<OpenEntry|EventEntry>
 * @psalm-type RecordsByScope = array<string, ScopedRecordList>
 * @psalm-type CloseByOpenIdMap = array<string, CloseEntry>
 * @psalm-type OperationIdSet = array<OperationId, true>
 * @psalm-type ParentByOpenId = array<OperationId, OperationId>
 * @psalm-type TypeCounts = array<LogType, int>
 * @psalm-type SchemaTypeMap = array<LogType, string>
 * @psalm-type DiagnosticCode = 'context_serialization_failed'|'close_without_open'|'close_id_mismatch'|'unclosed_at_flush'
 * @psalm-type DiagnosticData = array{
 *     code: DiagnosticCode,
 *     message: string,
 *     exceptionClass?: string,
 *     relatedId?: OperationId,
 *     discardedContext?: ContextData,
 *     unclosedIds?: list<OperationId>
 * }
 * @psalm-type TruncationData = array{
 *     limit: positive-int,
 *     dropped: positive-int,
 *     firstDroppedType: LogType
 * }
 * @psalm-type FrozenContext = array{
 *     type: LogType,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     diagnostic: DiagnosticData|null
 * }
 *
 * Serialized log entry types
 * @psalm-type EventEntryArray = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     openId?: OperationId
 * }
 * @psalm-type CloseEntryArray = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     openId: OperationId,
 *     profile?: OperationProfileData
 * }
 * @psalm-type OpenEntryArray = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     parentId?: OperationId
 * }
 * @psalm-type PublicClose = array{
 *     id: OperationId,
 *     type: LogType,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     profile?: OperationProfileData
 * }
 * @psalm-type PublicRecord = array{
 *     id: OperationId,
 *     type: LogType,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     log?: list<array<string, mixed>>,
 *     close?: PublicClose,
 *     openId?: OperationId,
 *     profile?: OperationProfileData
 * }
 * @psalm-type LogSessionArray = array{
 *     '$schema': SchemaUrl,
 *     log: list<PublicRecord>,
 *     links?: SchemaLinks
 * }
 *
 * @phpcs:enable
 */
final class Types
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}
