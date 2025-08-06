<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Semantic Logger Domain Types
 *
 * This file contains all type definitions for the semantic logger system.
 * These types ensure type safety while maintaini
 * ng flexibility for dynamic log data.
 *
 * Core data types:
 *
 * @psalm-type ContextData = array<string, mixed>
 * @psalm-type SchemaUrl = string
 * @psalm-type LogType = string
 * @psalm-type RelationType = string
 * @psalm-type SchemaLink = array{
 *     rel: RelationType,
 *     href: SchemaUrl,
 *     title?: string,
 *     type?: string
 * }
 * @psalm-type SchemaLinks = list<SchemaLink>
 * @psalm-type EventEntryArray = array{
 *     type: LogType,
 *     '$schema': SchemaUrl,
 *     context: ContextData
 * }
 * @psalm-type OpenCloseEntryArray = array{
 *     type: LogType,
 *     '$schema': SchemaUrl,
 *     context: ContextData,
 *     open?: array<string, mixed>
 * }
 * @psalm-type CloseEntryArray = EventEntryArray
 * @psalm-type LogSessionArray = array{
 *     '$schema': SchemaUrl,
 *     open: OpenCloseEntryArray,
 *     events?: list<EventEntryArray>,
 *     close?: CloseEntryArray,
 *     links?: SchemaLinks
 * }
 * @psalm-type EventEntryList = list<EventEntry>
 * @psalm-type OpenCloseEntryStack = list<OpenCloseEntry>
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
 *     jsonrpc?: string,
 *     method?: string,
 *     id?: int|string|null,
 *     params?: array<string, mixed>
 * }
 * @psalm-type McpToolCallParams = array{
 *     name?: string,
 *     arguments?: array<string, mixed>
 * }
 * @psalm-type McpSemanticAnalyzeArgs = array{
 *     script?: string,
 *     xdebug_mode?: string
 * }
 * @psalm-type McpLogData = array<string, mixed>
 */
final class Types
{
}
