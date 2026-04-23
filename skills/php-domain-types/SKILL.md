---
name: php-domain-types
description: Introduce, refactor, and normalize domain type aliases in PHP projects, especially codebases checked by Psalm and/or PHPStan. Use when Codex needs to create or reorganize a `Types.php` catalog, replace repeated array shapes with named aliases, add `@psalm-import-type` or `@phpstan-import-type`, decide whether a structure is a domain concept or only an implementation detail, or repair static-analysis issues caused by alias refactors.
---

# PHP Domain Types

## Overview

Introduce shared type aliases only for concepts that carry stable meaning across the project. Centralize them, import them where reused, and keep static-analysis compatibility intact.

## Workflow

1. Inspect the project before naming anything.
- Search for `@psalm-type`, `@phpstan-type`, `@psalm-import-type`, `@phpstan-import-type`, repeated `array{...}`, repeated `array<string, mixed>`, and repeated `list<...>`.
- Check `composer.json`, CI config, and analyzer config to see whether Psalm, PHPStan, or both are active.

2. Promote only real domain concepts into aliases.
- Promote serialized payloads, transport envelopes, link lists, profile payloads, stable config groups, keyed maps by IDs or types, and reused collection shapes.
- Keep local helper arrays, pass-by-reference accumulators, temporary analyzer workarounds, and concrete containers such as `SplStack` out of the catalog unless they are shared public concepts.

3. Design the catalog as a bounded type dictionary.
- Create one `Types.php` per namespace boundary or bounded context, not one repo-wide junk drawer.
- Make the catalog non-instantiable.
- Add `@psalm-suppress UnusedClass` if the class exists only to host aliases.
- Group aliases by concern: scalar atoms, collections, serialized shapes, analyzer interop.

4. Name aliases in domain language, not PHP plumbing language.
- Prefer names such as `UserId`, `SchemaUrl`, `RouteParams`, `ValidationErrorsByField`, `EventsByRequestId`, `PublicEventData`.
- Use suffixes consistently: `*List`, `*Map`, `*Set`, `*ById`, `*ByType`, `*Data`.
- Use `*Array` only when the concrete serialized array shape itself is important.
- Read [naming-patterns.md](references/naming-patterns.md) when names or boundaries are unclear.

5. Integrate aliases incrementally.
- Add aliases first.
- Replace only repeated or semantically important docblocks.
- Import shared aliases with `@psalm-import-type` and/or `@phpstan-import-type`.
- Keep native PHP signatures honest; do not widen runtime types to match docblocks.
- If a local helper becomes harder to understand after aliasing, leave it as a raw array annotation.

6. Keep analyzer compatibility explicit.
- Use tool-specific tags when the project runs both analyzers: `@psalm-type`, `@psalm-import-type`, `@psalm-return`, `@psalm-param`, `@phpstan-type`, `@phpstan-import-type`, `@phpstan-return`, `@phpstan-param`.
- Do not assume a tool-specific alias will be understood through a plain `@return` or `@param`.
- If optional keys become explicit and tests or analyzers start failing, update guards and fixtures instead of weakening the alias.

7. Validate after each meaningful batch.
- Run the analyzers and tests the project already uses.
- Treat parser errors, invalid imports, and optional-key warnings as feedback on the alias boundary.
- Prefer shrinking alias scope over adding suppressions when the catalog becomes fragile.

## Heuristics

- Introduce an alias when it clarifies a shared concept better than the raw shape.
- Avoid introducing an alias only because a shape is long.
- Split runtime structures from serialized structures when their meanings differ.
- Prefer one good alias imported in many places over many near-duplicate aliases.
- Rename weak aliases such as `Data`, `Info`, `Items`, or `Map` to something with a real subject.

## Quick Checks

- Does the alias express business, transport, persistence, or API meaning?
- Is the shape reused across files or likely to be imported?
- Does the alias make review easier than the raw shape?
- Is the alias naming a stable thing, not a temporary implementation trick?
- Will both active analyzers accept the way the alias is declared and imported?
