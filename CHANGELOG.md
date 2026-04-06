# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Organisation URLs on the dashboard:** Avoid `UrlGenerationException` (missing `organisation` route parameter) when `organisations.public_id` is null or empty. A ULID is assigned on save, existing rows are repaired when the route key is read, and migration `2026_04_07_000000_backfill_missing_organisation_public_ids` backfills any remaining null or blank values.
