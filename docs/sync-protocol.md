# ChampyStore Sync Protocol v2

Shared contract between the cloud API (`sync/v2/`), the Windows (WPF) app and the mobile app.

> Translated from the original French specification. Field and endpoint names are translated for readability;
> the actual code uses French names (e.g. `MouvementStock`, `ProduitSyncId`, `depuis`).
A company can use several devices (PCs and smartphones) at the same time, online or offline.

## Principles

1. **Offline first.** Each device works on its local SQLite database. Every local write marks the row `Synced = 0`.
2. **Global identifiers.** All relations use `SyncId` (GUID), never local auto-increment IDs.
   Examples: `SaleLine.SaleSyncId`, `StockMovement.ProductSyncId`.
3. **Soft delete.** Nothing is erased: `IsDeleted = 1`.
4. **Stock is computed by the server** from stock movements. A device never sends an absolute stock level.
5. **Incremental pull.** The server numbers every write; a device only fetches what changed since its last cursor.
6. **Authentication** by personal token only (`X-ChampyStore-Token` header).

## Server-side versions

- `company.LastVersion`: one counter per company.
- Every synchronised table has a `Version BIGINT` column.
- Every write (push, license activation, logo upload…) takes the next version **under a lock on the company row**
  (`SELECT … FOR UPDATE`). Writes of the same company are therefore serialised, and a pull can never "skip" a version
  that is committed later.

## Stock movements

| Field | Description |
|---|---|
| `SyncId` | Movement GUID — idempotency: a movement sent twice is counted once |
| `ProductSyncId` | Product concerned |
| `Quantity` | Signed delta: `-3` for a sale of 3, `+10` for a restock of 10 |
| `Type` | `INITIAL`, `SALE`, `RESTOCK`, `ADJUSTMENT`, `LOCAL`, `MIGRATION` (informational) |
| `Date`, `User` | Informational |

- Server stock of a product = `SUM(Quantity)` of its non-deleted movements.
- Stock displayed on a device = server stock + sum of local movements not yet sent (`Synced = 0`).
- Two simultaneous offline sales on two devices: both movements are counted and stock may go negative.
  This is intentional: the real discrepancy is visible instead of being silently overwritten.

## Push — `POST sync/v2/push`

JSON body, every list optional: `Company`, `License`, `Users`, `Suppliers`, `Customers`, `Products`,
`StockMovements`, `Expenses`, `Restocks`, `Sales`, `SaleLines`, `SalePayments`, `History`.

- `Products[].StockQuantity` is ignored, except for a product **new to the cloud** with no movement yet,
  where it becomes the initial stock.
- Role-based permissions are enforced server-side. Stock movements are accepted from every role (a sale moves stock).
- Data rejected by permissions gets its server version bumped: the next pull returns the server value,
  which replaces the local change.

```json
{ "success": true, "version": 1234, "ignored": ["users"] }
```

## Pull — `GET sync/v2/pull?since=<cursor>`

- First pull of a device: `since=-1`.
- Returns only rows with `Version > since`, deleted rows included (`IsDeleted = 1`).
- `company` and `license` are always returned in full.
- `cursor`: value for the next pull, to be saved **only after** the whole response has been applied.
- The cursor is read *before* the data, so a write committed during the pull is returned by the next pull
  (at worst twice, never lost).
- Stock movements are not returned; only the computed `products[].StockQuantity` is.
- Response is gzip-compressed when the client sends `Accept-Encoding: gzip`.

## Client sync sequence

1. Obtain a personal token if needed.
2. Read every `Synced = 0` row and push it.
3. If the push succeeds, mark `Synced = 1` **only the rows that were sent** (by `SyncId`).
   A row modified during the sync stays at `0`.
4. Pull with the saved cursor.
5. Apply the response. **Never overwrite a local `Synced = 0` row**: an unsent local change wins until it is sent.
   For stock: `StockQuantity = server stock + unsent local movements`.
6. Save the new cursor.

## v1 compatibility

Older app versions keep using the v1 endpoints during the transition. The v1 push:
- numbers its writes, so v2 devices receive them;
- converts the absolute stock it sends into an `ADJUSTMENT` movement (difference with the server stock).
