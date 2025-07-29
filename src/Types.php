<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Semantic Logger Domain Types
 *
 * This file contains all type definitions for the semantic logger system.
 * These types ensure type safety while maintaining flexibility for dynamic log data.
 *
 * Core data types:
 * @psalm-type ContextData = array<string, mixed>
 * @psalm-type SchemaUrl = string
 * @psalm-type LogType = string
 * @psalm-type RelationType = string
 *
 * Link types for RFC 8288 compliant web linking:
 * @psalm-type SchemaLink = array{
 *     rel: RelationType,
 *     href: SchemaUrl,
 *     title?: string,
 *     type?: string
 * }
 * @psalm-type SchemaLinks = list<SchemaLink>
 *
 * Log entry types that correspond to JSON schema structure:
 * @psalm-type EventEntryArray = array{
 *     type: LogType,
 *     '$schema': SchemaUrl,
 *     context: ContextData
 * }
 * @psalm-type OpenCloseEntryArray = array{
 *     type: LogType,
 *     '$schema': SchemaUrl,
 *     context: ContextData,
 *     open?: OpenCloseEntryArray
 * }
 * @psalm-type CloseEntryArray = EventEntryArray
 *
 * Complete log session structure with optional links:
 * @psalm-type LogSessionArray = array{
 *     '$schema': SchemaUrl,
 *     open: OpenCloseEntryArray,
 *     events?: list<EventEntryArray>,
 *     close?: CloseEntryArray,
 *     links?: SchemaLinks
 * }
 *
 * Collection types for internal use:
 * @psalm-type EventEntryList = list<EventEntry>
 * @psalm-type OpenCloseEntryStack = list<OpenCloseEntry>
 */
final class Types
{
}
