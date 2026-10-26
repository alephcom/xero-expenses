# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Dashboard team management for org admins:** On the dashboard, admins can list invitations (pending, expired, accepted), resend or revoke outstanding invites, and list members with role changes or removal, while keeping at least one admin per organisation.

- **Invoice checkbox when allocated in Xero:** Lines whose billable link already has a target transaction show the Invoice? checkbox as checked and disabled (reconcile still stores `needs_invoicing` false for “not pending”).

- **SPEND sync excludes deleted bank transactions:** Bank transaction requests use `Status=="AUTHORISED"` so Xero `DELETED` spend rows are not imported or shown in review.

- **ACCPAY sync excludes deleted and voided bills:** Invoice list queries restrict to `DRAFT`, `SUBMITTED`, `AUTHORISED`, and `PAID`; `DELETED` and `VOIDED` are omitted (with an extra skip if the API ever returns them).

- **Lock billable edits when invoiced in Xero:** Linked transactions that include a `TargetTransactionID` (allocated to a sales invoice) are stored locally; reconcile sets `needs_invoicing` false, the review UI disables invoice and invoice-to controls, and write-back skips creating, updating, or deleting those links (API payloads with a target are never mutated).

- **Xero billable expense write-back:** When you mark a line for invoicing, choose an invoice-to contact, or save notes, the app queues a job that updates Xero. Billable expenses use the Linked Transactions API (SPEND and ACCPAY lines). Notes are appended via History on the parent bank transaction or bill, prefixed so multi-line parents stay distinguishable. Linked transaction sync now stores every link from Xero (including drafts without a contact) plus each link’s Xero id. Line reviews record the last pushed note text for deduplication and any push error for display in the expanded row.
- **Invoice flag from Xero:** After linked transactions are synced, any expense line with a billable link that includes a customer in Xero gets `needs_invoicing` and `selected_contact_id` updated to match. Existing notes and reviewed state on a line review are left unchanged when reconciling.

### Changed

- **Dashboard invitations UI:** The separate invite-only Livewire block is replaced by **Team and invitations** (`ManageOrganisationTeam`), which keeps sending invites and adds invitation and member management for admins.

- **Invoice? for open billable links:** If a line is linked to a customer in Xero but not yet on a sales invoice, the Invoice? checkbox remains enabled. Unchecking clears `needs_invoicing` and write-back deletes the Xero linked transaction (removing the customer link).

### Fixed

- **Xero SSO reviewer access is now kept in step with Xero:** Memberships record how they were created. Reviewer access granted automatically at sign-in is removed on a later sign-in once Xero no longer lists the user with an allowed role (never on a failed Xero request). Members added by invitation, Connect Xero, or an admin role change are not touched. When an admin removes a member, sign-in no longer re-adds them; accepting a new invitation lifts that. Existing memberships are treated as manual.
- **Currency fallback:** Sync stores each organisation's Xero base currency and uses it for transactions without a currency code, instead of USD.
- **Contact picker payload:** Archived Xero contacts are kept for display but hidden from the Invoice to picker, and the contact list is fetched once when the page loads instead of being embedded in every render (including the two-second sync polling).
- **`reviewed_at` preserved:** Reconciling billable links from Xero no longer overwrites when a person last edited a line, and rows created by sync leave it empty.
- **`composer.lock`** content hash refreshed to match `composer.json`.
- **Xero token refresh race:** Token refreshes are serialised per OAuth batch with a cache lock, and skipped when another worker already rotated the token. Xero refresh tokens are single-use, so concurrent sync and write-back jobs could previously invalidate the connection.
- **Sync no longer empties data on failure:** Accounts, contacts, expense lines, and linked transactions are fetched from Xero first and then swapped in a database transaction, so a failed request mid-sync leaves the previous data in place.
- **Write-back API usage:** After a push, only the affected transaction's billable links are re-fetched instead of every link in the organisation. Pushes for the same organisation no longer run concurrently (prevents duplicate links).
- **Stuck syncs:** Queued or running sync runs untouched for 20 minutes are marked failed so **Refresh from Xero** is not blocked forever after a worker crash. The sync job sets a 900s timeout and the database queue's default `retry_after` is raised to 960s to match.
- **Sync date range validation:** **Refresh from Xero** rejects invalid ranges and ranges over 366 days.
- **Xero callback errors:** Cancelling on Xero's consent screen, or a failed token exchange when connecting, now returns a friendly message instead of a validation bounce or server error.

## [0.1.0] - 2026-04-06

First released version: Laravel application to connect Xero tenants, sync expense-related data, review lines in the browser, invite organisation members, and sign in with Xero SSO where configured.

### Fixed

- **Organisation URLs on the dashboard:** Avoid `UrlGenerationException` (missing `organisation` route parameter) when `organisations.public_id` is null or empty. A ULID is assigned on save, existing rows are repaired when the route key is read, and migration `2026_04_07_000000_backfill_missing_organisation_public_ids` backfills any remaining null or blank values.

[Unreleased]: https://github.com/alephcom/xero-expenses/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/alephcom/xero-expenses/releases/tag/v0.1.0
