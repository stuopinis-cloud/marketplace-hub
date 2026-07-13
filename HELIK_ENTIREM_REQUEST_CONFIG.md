# Helikon / Direct-Action — Entirem API Request Configuration

**Generated:** 2026-07-13  
**Mode:** Read-only analysis  
**Secrets exposed:** No (no bearer token, no credential values)

---

## Executive summary

| Question | Answer |
|----------|--------|
| **Exact request body found** | **Yes** |
| **Source** | Platform PostgreSQL `source_feeds.request_body_json` for `SourceFeed.name = 'helik'` |
| **Items type** | **array** (`[]` in production) |
| **Categories type** | **array** (`[]` in production) |
| **Report path** | `/Users/matas/Documents/Marketplace hub/HELIK_ENTIREM_REQUEST_CONFIG.md` |

---

## Production request body (safe structure)

Queried read-only from the working old app database on **2026-07-13**.

```json
{
  "Items": [],
  "Categories": []
}
```

| Field | Production value | Type | Notes |
|-------|------------------|------|-------|
| `Items` | `[]` | **array** | Empty array in live production config |
| `Categories` | `[]` | **array** | Empty array in live production config |

### Element types inside `Items` / `Categories`

**Not evidenced in production.** Both arrays are empty in the live `requestBodyJson`, so the element type (string, integer, object, etc.) cannot be determined from production data. The Entirem API accepts empty arrays and returns the full stock list (~9,588 rows in the latest successful dry-run on 2026-07-13).

Do **not** invent category IDs or item filters unless Entirem documentation or a future UI change populates these arrays.

---

## Database sources inspected

### 1. `SourceFeed` (primary — production)

| Field | Value |
|-------|-------|
| Table | `source_feeds` |
| Row `name` | `helik` |
| Row `id` | `cmqtcn8c0000nrk60za19m7fp` |
| `sourceUrl` | `https://api.entirem.com/api/v1/stocks` |
| `httpMethod` | `POST` |
| `authType` | `BEARER_TOKEN` |
| `responseType` | `JSON` |
| `dataPath` | `Value` |
| `requestHeadersJson` | `{}` (empty object) |
| `requestBodyJson` | `{ "Items": [], "Categories": [] }` |
| `configJson` | `{}` (empty object) |
| `credentialsJson` field names only | `token` |
| `updatedAt` | `2026-07-13T08:52:25.105Z` |

### 2. `MappingProfile` (linked)

| Field | Value |
|-------|-------|
| Row `id` | `cmqtcp6yc000prk60kamrb61m` |
| Row `name` | `helik mapping` |
| `fieldMappingJson.fields.sku` | `SKU` |
| `fieldMappingJson.fields.stock` | `Quantity` |
| `fieldMappingJson.fields.availability` | `Quantity` |
| `fieldMappingJson.dataPath` | `Value` |
| `matchRulesJson.strategy` | `SKU_AND_VENDOR` |
| Vendor scope (`transformRulesJson.allowedShopifyVendors`) | `Helikon-Tex`, `Direct-Action` |

`MappingProfile` does **not** contain request body values. It only defines response field mapping and vendor scope.

### 3. `SyncRun` (latest successful runs)

Latest dry-run (`2026-07-13T08:52:26Z`, mode `DRY_RUN`, status `SUCCESS`):

- `totalFeedItems`: **9588**
- `feedParseCompleteness`: **FULL**
- `bytesRead`: **444240**
- Uses the same `requestBodyJson` above (feed updated 1s before the run)

`SyncRun.statsJson` / `summaryJson` do **not** store the HTTP request body. They only store parse/match/sync statistics.

### 4. Legacy SQLite `SupplierFeed`

- Path: `shopify-supplier-feed-sync/prisma/dev.sqlite`
- Rows: **0** (no legacy helik config)

### 5. Exported migration reports (secondary confirmation)

- `shopify-supplier-feed-sync/REAL_SUPPLIER_CONFIGS.md` — documents field names `Items`, `Categories` but not values
- `shopify-supplier-feed-sync/REAL_SUPPLIER_CONFIGS.json` — same; values not exported (by design)

---

## Where values come from at runtime (old app)

There is **no code path that builds `Items` or `Categories` dynamically**.

Flow:

1. `SourceFeed.requestBodyJson` is loaded from PostgreSQL.
2. `sourceFeedToFetchAuth()` passes it through unchanged (`platform/lib/source-connectors/feed-fetch-auth.ts`).
3. `buildFeedHttpRequest()` serializes it with `JSON.stringify()` (`platform/lib/source-connectors/feed-request-builder.ts`).
4. `fetchFeedHttpResponse()` sends the POST (`platform/lib/source-connectors/feed-http-client.ts`).
5. Response is parsed; rows extracted from path `Value` (`platform/lib/feed-sync/parse-full-feed.ts` → `extractAllJsonRows()`).

Admin UI stores the body verbatim in `requestBodyJson` via the source feed form (`platform/components/feeds/source-feed-form.tsx`).

---

## Actual API call (old app)

### HTTP

| Setting | Value |
|---------|-------|
| Method | `POST` |
| URL | `https://api.entirem.com/api/v1/stocks` |

### Headers (token value redacted)

| Header | Value |
|--------|-------|
| `Authorization` | `Bearer <token>` — from decrypted `credentialsJson.token` |
| `Content-Type` | `application/json` — set automatically when POST body is present |
| `Accept` | `application/json` — because `responseType = JSON` |
| `User-Agent` | `IntegrationHub-FeedClient/1.0` |
| Custom headers (`requestHeadersJson`) | **none** (`{}`) |

Built by: `platform/lib/source-connectors/auth-headers.ts` → `buildAuthHeaders()`  
Request assembly: `platform/lib/source-connectors/feed-request-builder.ts` → `buildFeedHttpRequest()`

### Body serialization

```typescript
JSON.stringify(requestBodyJson)
// production result:
// {"Items":[],"Categories":[]}
```

If `requestBodyJson` were a string, the old app would validate it as JSON and send the string as-is (`serializeJsonRequestBody()`). Production uses an object, not a string.

### Response parsing

| Step | Detail |
|------|--------|
| Parse body | `JSON.parse(responseBody)` |
| Data path | `Value` (from `SourceFeed.dataPath` and `MappingProfile.fieldMappingJson.dataPath`) |
| Row extraction | `extractItemsByDataPath(data, "Value")` → array of objects |
| Per-row fields | `SKU` → sku, `Quantity` → stock/availability |
| Code | `platform/lib/source-connectors/api-source-connector.ts` (`extractItemsByDataPath`) |
| Normalization | `platform/lib/feed-sync/json-full-parser.ts` (`extractAllJsonRows`) |

Expected row shape:

```json
{
  "SKU": "ABC123",
  "Quantity": 12
}
```

---

## Marketplace Hub equivalent (current Laravel app)

| Old app | Marketplace Hub |
|---------|-----------------|
| `SourceFeed.requestBodyJson` | `suppliers.config.request_body` |
| `SourceFeed.dataPath` | `suppliers.config.response_data_path` (`Value`) |
| `credentialsJson.token` | `suppliers.credentials.token` (encrypted) or `ENTIREM_API_TOKEN` |
| Fetch | `app/Services/Suppliers/Helik/HelikFeedClient.php` |
| Parse | `app/Services/Suppliers/Helik/HelikResponseParser.php` |

### Recommended `suppliers.config` for Helikon

```json
{
  "response_data_path": "Value",
  "request_body": {
    "Items": [],
    "Categories": []
  }
}
```

### Laravel HTTP behavior (`HelikFeedClient`)

| Setting | Value |
|---------|-------|
| Method | `POST` via `Http::post()` |
| Auth | `Http::withToken($token)` → `Authorization: Bearer <token>` |
| Content-Type | `application/json` (Laravel JSON client default) |
| Body | `$supplier->config['request_body']` encoded as JSON |
| Response path | `HelikResponseParser` reads `Value` (default) |

---

## Marketplace Hub provisioning note

`SupplierProvisioner::ensureHelikSupplier()` currently seeds `request_body` as `{}` (empty object). Production uses **`Items` + `Categories` as empty arrays**, not an empty object. Update the helik supplier config to match production before the first real sync.

Example (Tinker):

```php
$supplier = \App\Models\Supplier::where('code', 'helik')->first();
$supplier->update([
    'config' => array_merge($supplier->config ?? [], [
        'response_data_path' => 'Value',
        'request_body' => [
            'Items' => [],
            'Categories' => [],
        ],
    ]),
]);
```

---

## Verification evidence

| Check | Result |
|-------|--------|
| Production DB `requestBodyJson` | `{ "Items": [], "Categories": [] }` |
| Latest successful API dry-run | 2026-07-13, 9588 items, FULL parse |
| Runtime body builder | None — DB value used as-is |
| Legacy app SQLite | No helik row |
| Secrets in this report | None |

---

## Final checklist

- **exact request body found:** yes
- **source DB field or code path:** `source_feeds.request_body_json` (`SourceFeed.requestBodyJson`), read at runtime by `feed-fetch-auth.ts` → `feed-request-builder.ts` → `feed-http-client.ts`
- **Items type:** array (production value: `[]`)
- **Categories type:** array (production value: `[]`)
- **report path:** `HELIK_ENTIREM_REQUEST_CONFIG.md`
