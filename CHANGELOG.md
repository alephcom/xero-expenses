# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Xero billable expense write-back:** When you mark a line for invoicing, choose an invoice-to contact, or save notes, the app queues a job that updates Xero. Billable expenses use the Linked Transactions API (SPEND and ACCPAY lines). Notes are appended via History on the parent bank transaction or bill, prefixed so multi-line parents stay distinguishable. Linked transaction sync now stores every link from Xero (including drafts without a contact) plus each link’s Xero id. Line reviews record the last pushed note text for deduplication and any push error for display in the expanded row.

## [0.1.0] - 2026-04-06

First released version: Laravel application to connect Xero tenants, sync expense-related data, review lines in the browser, invite organisation members, and sign in with Xero SSO where configured.

### Fixed

- **Organisation URLs on the dashboard:** Avoid `UrlGenerationException` (missing `organisation` route parameter) when `organisations.public_id` is null or empty. A ULID is assigned on save, existing rows are repaired when the route key is read, and migration `2026_04_07_000000_backfill_missing_organisation_public_ids` backfills any remaining null or blank values.

[Unreleased]: https://github.com/alephcom/xero-expenses/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/alephcom/xero-expenses/releases/tag/v0.1.0
