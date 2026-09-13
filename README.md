# DataPipe

A backend-focused, **API-only** service for importing large data files and generating reports from them — built with Laravel.

> **What this repository is about.** DataPipe is a portfolio project with a deliberate goal: to demonstrate *backend depth*, not just CRUD. It is built one concept at a time — each topic is studied in depth first, then implemented — so that every layer of the system is understood, not just wired together. The domain (data import + reporting) is intentionally generic; the point is the engineering underneath it: memory-safe file streaming, asynchronous processing, caching, and thorough testing.

## The idea

Users upload large data files (CSV). The system:

1. accepts the upload **memory-safely** (streamed, never loaded whole into RAM),
2. validates it,
3. processes it into structured records,
4. and exposes aggregated **reports** on demand.

There is **no frontend** — the API is consumed and tested via `curl` / Postman. This keeps the focus entirely on backend quality: clean REST conventions, consistent response envelopes, correct HTTP status codes, and robust error handling.

## Tech stack

| Layer     | Choice                                    |
| --------- | ----------------------------------------- |
| Language  | PHP 8.x                                   |
| Framework | Laravel (JSON API)                        |
| Auth      | Laravel Sanctum (bearer tokens)           |
| Testing   | Pest                                       |
| Client    | Postman collection / `curl` (no frontend) |

## How this project is built

DataPipe grows in layers, and each layer is a deliberate learning-then-building cycle: **first understand a topic deeply, then implement it in the project.** The roadmap:

| Round | Topic                        | What it adds                                                        | Status         |
| ----- | ---------------------------- | ------------------------------------------------------------------ | -------------- |
| 1     | **File System / Streaming**  | Memory-safe streaming upload, validation, atomic writes            | 🚧 in progress |
| 2     | **Collections**              | Transforming and aggregating parsed records                         | ⏳ planned      |
| 3     | **Testing (Pest)**           | A real test suite covering the import and validation logic          | ⏳ planned      |
| 4     | **Queues**                   | Large imports run asynchronously, with progress tracking           | ⏳ planned      |
| 5     | **Caching**                  | Reports are cached with deliberate invalidation                    | ⏳ planned      |

Each round leaves the project in a working, self-contained state.

## Getting started

> Setup instructions will be added as the first milestone lands.

```bash
# clone
git clone <repo-url>
cd date-pipe

# install (once the Laravel app is scaffolded)
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# run
php artisan serve
```

## Development

```bash
composer test        # Pest test suite
vendor/bin/pint      # code style (Laravel Pint)
```

## Status

Early development. Round 1 (memory-safe streaming upload) is being implemented. This README will grow with the project — architecture notes and design decisions are documented in my own words as each layer is built.

## License

MIT
