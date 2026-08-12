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
 * @psalm-type EventEntryList = list<EventEntry>
 * @psalm-type OpenCloseEntryList = list<OpenCloseEntry>
 * @psalm-type OpenChildrenByParent = array<string, list<OpenCloseEntry>>
 * @psalm-type CloseByOpenIdMap = array<string, EventEntry>
 * @psalm-type EventsByOpenIdMap = array<string, list<EventEntry>>
 * @psalm-type OperationIdSet = array<OperationId, true>
 * @psalm-type TypeCounts = array<LogType, int>
 * @psalm-type SchemaTypeMap = array<LogType, string>
 * @psalm-type DiagnosticKind = 'context_serialization_failed'|'close_without_open'|'close_id_mismatch'|'unclosed_at_flush'
 * @psalm-type DiagnosticData = array{
 *     kind: DiagnosticKind,
 *     message: string,
 *     exceptionClass?: string,
 *     relatedId?: OperationId,
 *     discardedContext?: ContextData,
 *     unclosedIds?: list<OperationId>
 * }
 * @psalm-type FrozenContext = array{
 *     type: LogType,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     diagnostic: DiagnosticData|null
 * }
 * @psalm-type LogTree = array{
 *     open: OpenCloseEntryList,
 *     close: EventEntryList
 * }
 *
 * Serialized log entry types
 * @psalm-type EventEntryArray = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     openId?: OperationId,
 *     close?: list<JsonMap>,
 *     profile?: OperationProfileData
 * }
 * @psalm-type OpenCloseEntryArray = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     open?: list<JsonMap>
 * }
 * @psalm-type PublicEventEntry = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     openId?: OperationId
 * }
 * @psalm-type PublicCloseEntry = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     openId?: OperationId,
 *     profile?: OperationProfileData
 * }
 * @psalm-type PublicOpenEntry = array{
 *     type: LogType,
 *     id: OperationId,
 *     schemaUrl: SchemaUrl,
 *     context: ContextData,
 *     events?: list<PublicEventEntry>,
 *     close?: PublicCloseEntry,
 *     open?: list<JsonMap>
 * }
 * @psalm-type CloseEntryArray = PublicCloseEntry
 * @psalm-type LogSessionArray = array{
 *     '$schema': SchemaUrl,
 *     open: list<PublicOpenEntry>,
 *     events?: list<PublicEventEntry>,
 *     close?: array<array-key, PublicCloseEntry>,
 *     links?: SchemaLinks
 * }
 *
 * MCP types
 * @psalm-type McpJsonRpcError = array{
 *     code: int,
 *     message: string
 * }
 * @psalm-type McpServerInfo = array{
 *     name: string,
 *     version: string
 * }
 * @psalm-type McpCapabilities = array{
 *     tools: object
 * }
 * @psalm-type McpInitializeResult = array{
 *     protocolVersion: string,
 *     serverInfo: McpServerInfo,
 *     capabilities: McpCapabilities
 * }
 * @psalm-type McpPropertySchema = array{
 *     type: string,
 *     description?: string,
 *     default?: string
 * }
 * @psalm-type McpInputSchema = array{
 *     type: string,
 *     properties: object|array<string, McpPropertySchema>,
 *     required?: list<string>
 * }
 * @psalm-type McpTool = array{
 *     name: string,
 *     description: string,
 *     inputSchema: McpInputSchema
 * }
 * @psalm-type McpToolsList = list<McpTool>
 * @psalm-type McpToolsListResult = array{
 *     tools: McpToolsList
 * }
 * @psalm-type McpContent = array{
 *     type: string,
 *     text: string
 * }
 * @psalm-type McpContentList = list<McpContent>
 * @psalm-type McpToolCallResult = array{
 *     content: McpContentList,
 *     isError: bool
 * }
 * @psalm-type McpJsonRpcResponse = array{
 *     jsonrpc: string,
 *     id: int|string|null,
 *     result?: McpInitializeResult|McpToolsListResult|McpToolCallResult,
 *     error?: McpJsonRpcError
 * }
 * @psalm-type McpJsonRpcRequest = array{
 *     jsonrpc: string,
 *     method: string,
 *     id?: int|string|null,
 *     params?: array<string, mixed>
 * }
 * @psalm-type McpToolCallParams = array{
 *     name: string,
 *     arguments?: JsonMap
 * }
 * @psalm-type McpSemanticAnalyzeArgs = array{
 *     script?: string,
 *     xdebug_mode?: string
 * }
 * @psalm-type McpLogData = JsonMap
 * @phpcs:enable
 */
final class Types
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}
