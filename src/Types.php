<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Semantic Logger Domain Types
 *
 * This file contains all type definitions for the semantic logger system.
 * These types ensure type safety while maintaining flexibility for dynamic log data.
 */
final class Types
{
}

/**
 * Core data types
 *
 * @psalm-type ContextData = array<string, mixed>
 * @psalm-type SchemaUrl = string
 * @psalm-type LogType = string
 * @psalm-type RelationType = string
 */

/**
 * Relation types for ALPS/JSON-LD style linking
 *
 * @psalm-type SchemaRelation = array{
 *     rel: RelationType,
 *     href: SchemaUrl,
 *     title?: string,
 *     type?: string
 * }
 * @psalm-type SchemaRelations = list<SchemaRelation>
 */

/**
 * Log entry types that correspond to JSON schema structure
 *
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
 */

/**
 * Complete log session structure with optional relations
 *
 * @psalm-type LogSessionArray = array{
 *     '$schema': SchemaUrl,
 *     open: OpenCloseEntryArray,
 *     events?: list<EventEntryArray>,
 *     close?: CloseEntryArray,
 *     relations?: SchemaRelations
 * }
 */

/**
 * Collection types for internal use
 *
 * @psalm-type EventEntryList = list<EventEntry>
 * @psalm-type OpenCloseEntryStack = list<OpenCloseEntry>
 */
