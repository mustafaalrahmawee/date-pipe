# Milestone 1 Evaluation Report — Memory-Safe Streaming Upload

**Project:** DataPipe (`api/` — Laravel 13, PHP 8.3+, SQLite, API-only, no frontend)
**Scope of this report:** the single production endpoint `POST /api/imports` plus its supporting layers (auth, validation, exception handling, persistence).
**Deliberately out of scope (later roadmap rounds):** CSV parsing into records, Collections, Pest test suite, queues, caching. Do not penalize their absence in this milestone.

**Update 2026-09-15:** Milestone 2 (Round 2 — Collections & Parsing) is covered by the addendum below, starting at section 9. Milestone 1 sections are unchanged.

This document is written for an independent reviewer/agent to evaluate the implementation: architecture, decisions with their rejected alternatives, reproducible tests with expected outputs, and honest limitations.

---

## 1. File inventory (all under `api/`)

| File | Role |
|---|---|
| `routes/api.php` | Route definitions; import route behind `auth:sanctum` |
| `app/Http/Controllers/AuthController.php` | `register` / `login` — thin, returns `{data: {user, token}, message}` |
| `app/Http/Controllers/ImportController.php` | `store` — orchestration only: service call + ownership record + response |
| `app/Http/Requests/RegisterRequest.php` | Form Request: name/email/password rules |
| `app/Http/Requests/LoginRequest.php` | Form Request: email/password rules |
| `app/Http/Requests/StoreImportRequest.php` | Form Request: shape only (`required`, `file`) |
| `app/Exceptions/ImportUploadException.php` | Domain base exception, `status(): 400` |
| `app/Exceptions/FileTooLargeException.php` | `status(): 413` |
| `app/Exceptions/InvalidFileTypeException.php` | `status(): 415` |
| `app/Exceptions/InvalidCsvException.php` | `status(): 422` |
| `app/Services/ImportUploadService.php` | Core file mechanics: size/MIME/header validation on the PHP temp file, then storage |
| `app/Services/ImportUploadResult.php` | Immutable DTO: path, originalName, sizeBytes, header |
| `app/Models/Import.php` | ULID primary key, `user_id` FK (NOT fillable), `header` array cast |
| `app/Models/User.php` | Added `HasApiTokens`, `imports(): HasMany` |
| `bootstrap/app.php` | JSON error rendering for `api/*`, `dontReport(ImportUploadException)`, render closure mapping `status()` → HTTP status |
| `config/imports.php` | `max_upload_bytes` (env `IMPORT_MAX_UPLOAD_BYTES`, default 100 MB) |
| `database/migrations/2026_09_14_000000_create_imports_table.php` | imports table |

## 2. Request flow

```
POST /api/imports  (Bearer token)
  → auth:sanctum middleware                     → 401 on failure
  → StoreImportRequest                          → 422 for shape errors (missing file)
  → ImportController@store
      → ImportUploadService::store(UploadedFile)
          1. getRealPath() + false-guard        → 400 if temp file already gone
          2. assertSize(): filesize(temp)       → 413 FileTooLargeException
          3. assertMimeType(): finfo whitelist  → 415 InvalidFileTypeException
             (text/csv, text/plain)
          4. readHeader(): fopen/fgetcsv on the temp stream,
             try/finally fclose                 → 422 InvalidCsvException
             (a blank first line yields [null] and is caught by the
             empty-column check, not the no-header guard; empty
             columns; duplicate columns)
          5. persist(): storeAs('imports', bin2hex(random_bytes(16)).'.csv',
             disk: local, private) — Flysystem stream copy, RAM-constant
          6. returns ImportUploadResult — note: sizeBytes in the DTO
             comes from Storage::disk()->size($storedPath) (the stored
             file), while the size *check* in step 2 used
             filesize($tempPath) (the temp file); identical values in
             practice, two distinct sources
      → $request->user()->imports()->create([...])   sets user_id automatically
      → 201 {data: <Import>, message}
```

Invalid files never reach `storage/app/private/imports/` — all content checks run on the temp file *before* persistence.

## 3. Status code contract

| Status | Trigger | Thrown/rendered where |
|---|---|---|
| 401 | Missing/invalid token | Sanctum → native `{"message":"Unauthenticated."}` |
| 422 | Missing `file` field | Form Request → native `{"message","errors"}` |
| 413 | File over `imports.max_upload_bytes` (app-level; see decision 3 for the PHP-level zones) | `FileTooLargeException` → render closure |
| 415 | Content MIME not in whitelist | `InvalidFileTypeException` → render closure |
| 422 | Header missing/empty/duplicate columns | `InvalidCsvException` → render closure |
| 400 | I/O fallback (temp file gone, store failed) | `ImportUploadException` base |
| 201 | Success | Controller |

Success envelope: `{data: {...}, message}`; error envelope: Laravel-native `{message}` / `{message, errors}` — deliberately consistent with the framework.

## 4. Key design decisions (each with the rejected alternative)

1. **Storage facade for persistence, raw PHP streams for content.** The service uses `finfo`/`fopen`/`fgetcsv` on the raw temp path (content knowledge required), and `$file->storeAs()` for the final write (path resolution, disk abstraction, stream copy). Alternative — doing everything raw or everything via facade — was rejected: the facade hides exactly what this milestone is meant to make explicit, while re-implementing path/visibility handling adds no learning or safety value.
2. **Validation order: size → MIME → header, all before storage.** Invalid files never touch the target directory; no delete-based cleanup path exists because nothing invalid is ever written there. (`delete()`-on-failure designs leave the window open between write and cleanup.)
3. **Size check in the service, not the Form Request `max` rule.** Laravel's `max` rule can only produce 422; the spec requires 413. `filesize()` on the temp file measures real bytes, untrusted client input irrelevant. Alternative (422→413 remapping by inspecting validator internals) rejected as fragile and it would make `FileTooLargeException` dead code.
   **Honest nuance — 413 reachability over HTTP:** PHP's own limits run *before* the framework, so three zones exist. With the local Herd values (`upload_max_filesize=2M`, `post_max_size=8M`):
   - **> post_max_size** → Laravel's `ValidatePostSize` middleware throws `PostTooLargeException` → native 413 (`vendor/laravel/framework/src/Illuminate/Http/Exceptions/PostTooLargeException.php:20`, `parent::__construct(413, …)`).
   - **> upload_max_filesize, ≤ post_max_size** → PHP flags the upload `UPLOAD_ERR_INI_SIZE`; the `file` rule fails → **422** ("The file failed to upload."), not 413. Documented mismatch of this milestone; aligning the ini values with `IMPORT_MAX_UPLOAD_BYTES` in production closes it.
   - **≤ both, but > 100 MB** → our `FileTooLargeException` → 413.
   With the local ini values the app-level 413 is therefore only reachable by direct service invocation (the smoke test below sets the config to 10 bytes); over HTTP the realistic local outcomes are the first two zones.
4. **`mimes` rule removed from the Form Request.** The rule guesses MIME from content (also finfo) and intercepted wrong-type files as 422, making `InvalidFileTypeException`/415 unreachable over HTTP. After removal, 415 is delivered by the service whitelist. Accepted trade-off: the file *extension* is no longer checked (content is what matters; `valid.txt` with CSV content is accepted — verified below).
5. **`storeAs()` with a CSPRNG name, fixed `.csv` extension, not atomic.** Verified in vendor code: `FilesystemAdapter::putFileAs()` opens the upload as a stream (`vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemAdapter.php:504` — `fopen(… $file->getRealPath(), 'r')`) and the local adapter writes via stream copy (`vendor/league/flysystem-local/LocalFilesystemAdapter.php:127` — `file_put_contents($location, $contents, …)`) — RAM-constant. Non-atomic is accepted for the receive path: single writer, no readers mid-copy. The temp+rename pattern is deferred to report exports (future round) where concurrent readers exist. Client filename is never used for storage, so path traversal via `original_name` is structurally impossible.
6. **Exception hierarchy with `status()` methods.** Domain exceptions stay HTTP-agnostic (not extending Symfony HttpExceptions); the HTTP mapping happens in exactly one place (`bootstrap/app.php` render closure + `dontReport` so expected client errors don't spam logs — subclass coverage verified: `vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:519`, `Arr::first($dontReport, fn ($type) => $e instanceof $type)`).
7. **ULID primary key + `HasUlids`.** Non-guessable public handle vs auto-increment. `user_id` is deliberately NOT fillable (mass-assignment defense); ownership is set exclusively server-side via `$user->imports()->create()` — verified: request body `user_id` cannot override, and a non-existent user id is rejected by the DB FK.
8. **Auth (support layer):** Sanctum bearer tokens; register/login via Form Requests; login checks credentials manually (`Hash::check`) instead of `Auth::attempt()` (session guard is stateful-world); identical error for unknown user vs wrong password (no user enumeration). `plainTextToken` visible exactly once.
9. **Failure-order reasoning:** file stored first, DB record second. If DB fails → orphan file on disk (harmless, GC later); the reverse would leave records pointing at nothing.

## 5. Reproducing the tests

Environment facts (Windows, local dev):
- PHP only via Laravel Herd: `"$USERPROFILE/.config/herd/bin/php.bat"` (not on Git Bash PATH).
- Herd's `php.ini` needed `upload_tmp_dir`/`sys_temp_dir` set to `C:\Users\musta\AppData\Local\Temp` — without it PHP rejects uploads at startup ("unable to create a temporary file"). This is a host-local env fix, intentionally not in the repo.
- DB: SQLite at `api/database/database.sqlite`, all migrations ran (`php artisan migrate`).

```bash
cd api
"$USERPROFILE/.config/herd/bin/php.bat" artisan serve --port=8000 &

TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"smoke@example.com","password":"secret123"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
# (or register a fresh user first via POST /api/auth/register with
#  {"name","email","password","password_confirmation"})

# 401 — unauthenticated
curl -s -w "\nHTTP %{http_code}\n" -X POST http://127.0.0.1:8000/api/imports \
  -H "Accept: application/json" -F "file=@valid.csv"
# → {"message":"Unauthenticated."}  HTTP 401

# 201 — valid CSV
curl -s -w "\nHTTP %{http_code}\n" -X POST http://127.0.0.1:8000/api/imports \
  -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" -F "file=@valid.csv"
# → {"data":{"path":"imports/<32hex>.csv","original_name":"valid.csv",
#    "size_bytes":70,"header":["name","email","amount"],"user_id":1,
#    "id":"01m2fr295enxqsk8xr367et1j9",...},"message":"Import created."}  HTTP 201

# 415 — PHP content disguised as .csv  (printf '<?php echo "pwn";' > fake.csv)
curl -s -w "\nHTTP %{http_code}\n" -X POST http://127.0.0.1:8000/api/imports \
  -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" -F "file=@fake.csv"
# → {"message":"Unsupported media type: text\/x-php."}  HTTP 415

# 422 — duplicate header columns (printf 'name,name\n1,2\n' > dup.csv)
#   → {"message":"Header row contains duplicate column names."}  HTTP 422

# 422 — no file attached
#   → {"message":"The file field is required.", ...}  HTTP 422

# 413 — service-level proof: set IMPORT_MAX_UPLOAD_BYTES=10 in .env,
#       config:clear, upload a larger file. Over HTTP with local Herd
#       ini (2M/8M) see decision 3: >8M → 413 via middleware,
#       2–8M → 422 ("The file failed to upload.").
#   → {"message":"File exceeds the maximum allowed size of 10 bytes."}  HTTP 413
```

Follow-up consistency check (verified in this session): the created DB row matches the response (`id` ULID, `user_id=1`, `header` decoded to array via cast) and `Storage::disk('local')->exists($row->path)` is true. A `php -r` mass-assignment probe (`Import::create([... 'user_id' => 999 ...])` against a nonexistent user) is rejected by the FK constraint.

## 6. Verified observations from this session's test run

| Case | Expected | Observed |
|---|---|---|
| No token | 401 | 401 `{"message":"Unauthenticated."}` |
| Valid CSV | 201 | 201, ULID id, `user_id:1` set automatically, header array |
| PHP disguised as .csv | 415 | 415 `Unsupported media type: text/x-php` |
| Duplicate header columns | 422 | 422 via `InvalidCsvException` |
| No file field | 422 | 422 native validation response |
| `.txt` extension, CSV content | 201 | 201 (extension not checked by design, see decision 4) |
| DB + disk after upload | consistent | row + file both present |

## 7. Known limitations (deliberate or documented — evaluate as such)

- **No automated tests.** Pest suite is roadmap round 3; this milestone was verified with the reproducible manual protocol above. This is a scope decision, not an omission to fix within round 1.
- **No login throttling** yet — noted, deferred.
- **Tokens accumulate** per login (no revocation/logout endpoint yet).
- **`storeAs()` is not atomic** — acceptable for single-writer receive path (decision 5); atomic write pattern arrives with report exports.
- **413 is not uniformly reachable over HTTP** (decision 3): files between `upload_max_filesize` and `post_max_size` are answered 422 ("The file failed to upload."). Aligning the ini values with `IMPORT_MAX_UPLOAD_BYTES` closes the gap in production.
- **Header validation covers the first line only** (empty/duplicate columns); row-level validation is round-2 territory.
- **Empty file** (`application/x-empty`) is rejected as 415 via the MIME whitelist rather than a dedicated message.
- **Orphan-file GC** (DB write fails after file stored) is not implemented yet — the failure order makes orphans harmless-but-possible; cleanup arrives with a later round.
- **Memory-safety nuance (honest framing):** PHP itself streams the multipart upload to its temp file before request handling; our RAM-constant guarantee covers everything after that (finfo/fgetcsv/flysystem all stream; nothing `file_get_contents`es the upload).

## 8. Suggested evaluation criteria

1. Correct, distinct status codes (413/415/422/400/401) with a single rendering point.
2. Content-based validation (finfo on real bytes, never client-supplied MIME), executed before persistence.
3. No resource leaks: every stream opened in the service is closed in `finally`; invalid uploads leave no files behind.
4. Storage security: CSPRNG filename, client name never used for storage, `private` visibility, non-public disk root (`storage/app/private`).
5. Ownership integrity: `user_id` not mass-assignable, set via relation, real FK with cascade.
6. Layering: thin controller, rules in Form Requests, mechanics in service, HTTP mapping in bootstrap.

---

# Milestone 2 Addendum — Collections & Parsing (Round 2)

**Date:** 2026-09-15 · **Scope:** processing the stored CSV into persisted records with constant memory, plus report, records and list endpoints. Pest suite remains roadmap round 3 (deliberate); round 2 is verified by the committed smoke suite `scripts/smoke-round2.sh` (section 13).

## 9. New file inventory (all under `api/` unless noted)

| File | Role |
|---|---|
| `database/migrations/2026_09_15_000000_create_records_table.php` | records table: auto-increment id, ULID FK `import_id` (cascade), `row_index`, typed columns `name`/`email`/`amount decimal(12,6)`, `unique(import_id, row_index)` |
| `app/Models/Record.php` | `amount => decimal:6` cast (exact strings, also in JSON), `import_id` not fillable, `$timestamps = false` |
| `app/Models/Import.php` | + `records(): HasMany` |
| `app/Services/ImportProcessingService.php` | core: `Storage::readStream` → Generator → `LazyCollection` → validate/map → `chunk(1000)` → `partition` → `DB::transaction` + batch insert |
| `app/Services/ImportProcessingResult.php` | readonly DTO: totalRows, validRows, invalidRows, errors (row + message) |
| `app/Services/ImportReportService.php` | single-pass `reduce` over a cursor stream, arithmetic via `Brick\Math\BigDecimal` (scale 6, HALF_UP) |
| `app/Exceptions/ImportProcessingException.php` | `status(): 500`, stays reportable (server-side I/O failures) |
| `bootstrap/app.php` | + second render closure for `ImportProcessingException`; deliberately NO `dontReport` (500s belong in the log) |
| `config/imports.php` | + `process_chunk_size` (1000), `max_reported_row_errors` (10) |
| `app/Http/Controllers/ImportController.php` | + `index`, `process`, `records`, `report`; user-scoped `findOrFail`, no route-model binding |
| `routes/api.php` | + 4 endpoints inside the `auth:sanctum` group |
| `scripts/smoke-round2.sh` (repo root) | committed smoke suite, see section 13 |

## 10. Processing flow

```
POST /api/imports/{id}/process  (auth:sanctum)
  → user-scoped findOrFail                        → 404 for foreign/unknown ids
  → guard 1: stored file header must equal the
    fixed contract [name, email, amount]          → 422 InvalidCsvException
  → guard 2: stream openable                      → 500 ImportProcessingException
  → delete existing records of this import        → idempotency (re-run replaces)
  → rows(): Storage::readStream → Generator yields [rowNumber, fields]
      (header row = 1, first data row = 2; fclose in finally)
  → LazyCollection::make(closure)                 → repeatable source, O(1) memory
  → map: validateRow → insert-ready record or error reason
      (column count; name non-empty ≤255; filter_var email ≤255;
       is_numeric(amount) — string flows through, no float cast)
  → chunk(1000) → each: partition valid/invalid → DB::transaction + bulk insert
  → counts accumulated, first 10 errors kept
  → 200 {data: {import_id, total_rows, valid_rows, invalid_rows, errors}, message}
```

Guards run **before** the delete, so a corrupt/missing file cannot destroy the records of the last successful run.

## 11. Round 2 status contract

| Status | Trigger |
|---|---|
| 200 | `POST /imports/{id}/process`, `GET /imports/{id}/records`, `GET /imports/{id}/report`, `GET /imports` |
| 401 | missing/invalid token (Sanctum, native) |
| 404 | import not owned by the authenticated user (user-scoped `findOrFail`; no existence leak) |
| 422 | stored file header does not match the `name, email, amount` contract (`InvalidCsvException`, same render closure as round 1) |
| 500 | stored file unreadable or insert failed (`ImportProcessingException`, rendered in `bootstrap/app.php`, logged) |

Invalid rows never fail the request: they are counted and reported (row number = CSV editor line number), valid rows are persisted.

## 12. Round 2 key decisions (each with the rejected alternative)

1. **Fixed schema** `name/email/amount` (user decision): typed columns enable real type validation and later SQL aggregation; generic JSON-per-row was rejected as undermining the round's goal. Only files with this header are processable — accepted for this project.
2. **Tolerate and count invalid rows** (user decision): fail-fast was judged wrong at row level for an import tool; file-level strictness (round 1) stays. Errors reported with editor line numbers, first 10 only (`max_reported_row_errors`), counters never truncated.
3. **Precision chain for `amount`:** `decimal(12,6)` column + `decimal:6` model cast + the **validated numeric string** inserted via query builder (model casts never run on `insert()` — the transform step is where precision is decided) + `BigDecimal` aggregation (raw PHP string addition would silently coerce to float). Measured on the 283,398-row fixture: float sum `34987407.089286` vs exact `34987407.089022` — the float error is real and reproducible. JSON returns amounts as strings (Stripe-style). SQLite stores `decimal` as float64 but guarantees lossless round-trips up to 15 significant digits; our values fit in 12 (documented in `ImportReportService`).
4. **Auto-increment id for records, ULID for imports:** records are high-volume and never addressed directly (only under `/imports/{id}/...`); 8 vs 26 bytes per row across millions of rows. Imports keep ULID as the public handle.
5. **Idempotent re-processing:** records of an import are deleted before a run, making a crashed partial run simply repeatable; a 409-style lock was rejected as a dead end for the user. Guards run before the delete (see section 10).
6. **Deliberate deviation from round-1 decision 8** ("service stays DB-free"): batch inserts in transactions are the mechanism of this round; a DB-free processing service would degrade the controller into a transaction driver. Sanctioned explicitly by the user. The upload service remains DB-free.
7. **Report reads raw cursor rows, not Eloquent models** — measured: model hydration 67 s vs raw cursor + BigDecimal 1.5 s for 283k rows (BigDecimal math itself is ~free; initial all-Eloquent version took 19 s end-to-end, cursor version 3.8 s). Precision is preserved by the 12-significant-digit invariant documented at the cast site; `toScale(6)` normalizes output format. SQL `GROUP BY` would be the production choice; PHP-side folding is the declared learning goal of the round (code comment states this honestly).
8. **`readHeader()` from round 1 is not refactored/shared** with the new line reader (different sources: temp path vs storage stream; no test net under it — Option A decision). Accepted duplication, first refactor candidate for the Pest round. Consistency is enforced by the header guard.
9. **Chunking:** 1000 rows × 5 params = 5000 bind values, far below SQLite's 32766 limit; config-driven. Records pagination: native paginator JSON, default 50, capped at 100.
10. **Ownership without route-model binding:** binding would fetch by id alone; user-scoped `findOrFail` turns foreign ids into 404 (no existence leak).

## 13. Reproducing the round-2 tests

```bash
# server in one terminal
cd api && php artisan serve

# smoke suite from the repo root (self-contained, ~10s)
bash scripts/smoke-round2.sh
```

Expected: every line `PASS`, final line `ALL TESTS PASSED`, exit code 0. The suite asserts exact JSON values, including `"sum":"10.800000"` for 0.1 + 0.2 + 10.5 (float would produce `10.800000000000001`-style drift somewhere in a naive pipeline). Optional section 9 uses `test-files/test-1mb.csv` when present (asserts exactly 1 invalid row — known last-line quirk of that fixture — and avg = min = max = `123.456789`). Env overrides: `API_BASE`, `SMOKE_EMAIL`, `SMOKE_PASSWORD`.

## 14. Verified observations (this session, dev machine)

| Case | Expected | Observed |
|---|---|---|
| 10 MB / 283,399-row file processed | constant memory | ~9 s, peak 30 MB, 6 MB growth after run |
| Report on 283,398 records | single streaming pass | 3.8 s after cursor switch (19.2 s before), same exact sum |
| Float vs BigDecimal sum (283,398 × 123.456789) | exact | float `34987407.089286`, BigDecimal `34987407.089022` |
| 0.1 + 0.2 + 10 + 7.25 | exact | `"17.550000"` |
| Double processing | no duplicates | identical counts, record total unchanged |
| Header mismatch on stored file | 422 | `InvalidCsvException` raised before delete |
| Missing stored file | 500 | `ImportProcessingException`, records of last run intact |
| Blank line mid-file | counted, not fatal | invalid row with line number |

## 15. Round 2 known limitations (deliberate or documented)

- Single fixed header contract (`name,email,amount`) — by design (decision 1).
- **Amount precision is only guaranteed for values within `decimal(12,6)` range (≤12 significant digits).** `validateRow` currently checks `is_numeric` only, and SQLite does not enforce the column's precision/scale (NUMERIC affinity → REAL), so an out-of-range amount such as `123456789.123456` is accepted and stored lossily — the exactness claim holds only for in-range values (all test fixtures qualify). Closing this is an input-validation task (a range/scale check in `validateRow`), independent of the streaming pipeline; deferred.
- Partial imports carry no status/progress field — queue round (4) territory.
- Report aggregation in PHP, not SQL — deliberate learning goal; production choice is `GROUP BY`.
- Smoke-suite imports persist (no DELETE endpoint yet) — they accumulate harmlessly in dev.
- Round-1 backlog carries forward: token revocation, throttling, orphan GC, 413 ini zones.
- Pest suite still pending — round 3; `scripts/smoke-round2.sh` is the round-2 regression net.

## 16. Suggested round-2 evaluation criteria

1. Constant memory while processing (stream entry, LazyCollection, no `file_get_contents`, chunked inserts).
2. Exact decimal handling end-to-end (string insert path, decimal:6 cast, BigDecimal aggregation, string JSON).
3. Correct, minimal status contract (200/401/404/422/500) with single-point rendering; 500 stays logged.
4. Ownership scoping on every new endpoint; no route-model binding bypass.
5. Idempotency and guard-before-delete ordering.
6. Honest layering: thin controller, mechanics in services, config-driven limits (chunk size, error cap, per-page cap).
