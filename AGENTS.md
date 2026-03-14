# Repository Guidelines

## Purpose

This repository contains small, dependency-light PHP clients for DNS providers.
The design goal is pragmatic scripting, not full SDK coverage.

## Design Principles

- Keep each provider client in a single PHP file.
- Avoid autoloaders, frameworks, and external runtime dependencies.
- Prefer readable imperative code over abstraction layers.
- Implement the small API subset needed for DNS zone and record workflows.
- Preserve a mostly shared method surface across providers where practical.

## Common API Shape

Provider classes generally expose a compatibility-oriented DNS surface:

- `ZonesList()`
- `ZoneCreate()`
- `ZoneDelete()`
- `ZoneListRecords()`
- `ZoneAddRecord()`
- `ZoneAddRecords()`
- `ZoneDeleteRecord()`
- `ZoneDeleteRecords()`
- `ZoneClone()`

Some providers also expose provider-native helpers such as `Domain*`, `Contact*`,
ID-based variants, or private/utility helpers prefixed with `_`.

## Implementation Conventions

- Use plain `curl` or other built-in PHP facilities only.
- Throw exceptions for transport or obvious API failures when that keeps usage simple.
- Return simple arrays, booleans, IDs, or provider payload fragments.
- Match provider terminology and payload keys closely instead of wrapping everything in custom models.
- Add compatibility wrappers when a provider lacks a direct operation, but keep them simple and explicit.
- Prefer small helper methods over large class hierarchies.

## Editing Guidance

- Preserve the existing style of the touched file instead of normalizing the whole repo.
- Keep comments short and practical.
- Do not introduce Composer, namespaces, traits, or shared base classes unless explicitly requested.
- If a provider API forces behavior differences, document that in comments rather than hiding it behind heavy abstraction.
- When aligning with upstream docs, verify both parameter names and pagination/default-limit behavior.

## Documentation

- Keep `README.md` focused on the project’s drop-in nature and minimal requirements.
- If behavior differs from a provider’s ideal API shape, prefer documenting that near the affected client method.
