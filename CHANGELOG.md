# Changelog

## Unreleased

- Add SQL dialect support and/or the platform capability needed by the dialect-aware executor; see README for migration and release ordering.


All notable changes to WilyCode Database Driver PDO are documented here.

## [Unreleased]

### Changed

- Renamed the Composer package to `wilycode/database-pdo`.
- Switched the shared contracts dependency to `wilycode/database`.
- Retained the existing `WilyCode\Database\DriverPdo\` PHP namespace.
- Declared `replace` for `wilycode/database-driver-pdo` at matching versions.

## [0.1.0] - 2026-10-13

### Added

- First public release of the PDO implementation of `DatabaseInterface`.
- `PdoBinder` integration with WilyCode Database Query parameter types.
- Tests for querying, binding, transactions, errors, and PDO configuration edge cases.
- GitHub Actions workflow for PHP 8.4 and `pdo_sqlite`.

### Fixed

- Explicit associative result fetching independent of connection-level fetch-mode options.
- Query preparation, parameter binding, and execution failures reported through `false` are now wrapped as `QueryException`.

[0.1.0]: https://github.com/wilycode/database-driver-pdo/releases/tag/v0.1.0
