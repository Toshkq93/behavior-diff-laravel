# Behavior Diff for Laravel

Checks that a Laravel application behaves the same after an upgrade: PHP 7.4 → 8.x, Laravel 8 → 12, Rector, a dependency bump.

The Laravel adapter of [toshkq93/behavior-diff](https://github.com/toshkq93/behavior-diff). It reads the routes, validation rules and model bindings, generates requests for every route, runs them **inside the application** on the old and the new version, and reports what changed: status codes, responses, exceptions, deprecations, sent mail, queued jobs, database writes.

```
Behavior diff  PHP 7.4.33 → PHP 8.1.34

Cases: 69 on 8 endpoints   identical: 61 (88.4%)
Different: critical: 0   high: 6   medium: 0   low: 2

GET /shop/user  2 cases
  GET /shop/user?id=abc  [id=abc, 4c5aef50fcef]
    HIGH Status 200 → 404

GET /api/price  3 cases
  GET /api/price?qty=abc  [qty=abc, 8351c804c8e2]
    HIGH Different exception: TypeError: Unsupported operand types: string * float
      app/Http/Controllers/ShopController.php:32
```

No production traffic, no manual clicking, no data left behind: every request runs in a rolled-back transaction.

## Requirements

- PHP 7.1+
- Laravel 5.5 to 13 (tested on Laravel 8 with PHP 7.4 / 8.1 and Laravel 13 with PHP 8.4)

## Installation

```bash
composer require --dev toshkq93/behavior-diff-laravel
php artisan vendor:publish --tag=behavior-diff-config   # optional
```

## Usage

```bash
# 1. Generate the requests
php artisan behavior-diff:scan

# 2. The old version, twice (the second run shows what is random)
php artisan behavior-diff:run --out=behavior-diff/old.jsonl
php artisan behavior-diff:run --out=behavior-diff/old-2.jsonl

# 3. The new version: another PHP, or the upgraded branch
php artisan behavior-diff:run --out=behavior-diff/new.jsonl

# 4. Compare
php artisan behavior-diff:diff behavior-diff/old.jsonl behavior-diff/new.jsonl \
    --noise=behavior-diff/old-2.jsonl --html=behavior-diff/report.html
```

A PHP upgrade with Docker, same code and database:

```bash
docker run --rm -v "$PWD":/app -w /app php:7.4-cli php artisan behavior-diff:run --out=behavior-diff/old.jsonl
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php artisan behavior-diff:run --out=behavior-diff/new.jsonl
```

A framework upgrade: run `scan` and the old run on the current branch, check out the upgrade branch, run again with the same `cases.json`, compare.

`behavior-diff:diff` exits with 1 when it finds differences of `medium` severity or higher (`--fail-on=critical|high|medium|low|never`), so it can gate CI.

## What is generated

`behavior-diff:scan` reads every route of the router:

| Source | Becomes |
|---|---|
| `posts/{post}` bound to `Post $post` | real route keys of two posts from the database, plus `999999999`, `abc`, `0` |
| FormRequest `rules()` | typed body / query fields: `required`, `integer`, `email`, `date`, `boolean`, `array`, `max:N`, `in:a,b` |
| `exists:users,id` | two real ids from the table |
| `Rule::in([...])`, `Rule::enum(...)` | their values |
| spatie/laravel-data objects in the signature | their validation rules |
| `$request->validate([...])`, `Validator::make(...)` in the action | their rules |
| `$request->input('q')`, `->integer('page')`, `request('sort')` | fields with a type from the method |
| `auth`, `can:`, `verified` middleware | the route is called as a guest and as each configured user |

Each route gets one request with valid values, then one request per field variant: missing, empty, `0`, `abc`, an array, out-of-range numbers, over-long strings. These are the inputs PHP 8 treats differently from PHP 7.

## What is compared

Every request runs through the HTTP kernel, like a feature test:

- **Isolation:** a transaction on each configured connection, rolled back after the request. Session and cache use the `array` driver.
- **Determinism:** the clock is frozen (`now`), the random seed is fixed, `Str::uuid()` (Laravel 6+) and `Str::random()` (Laravel 10+) are deterministic.
- **Auth:** the case user is set on the configured guards; CSRF is not checked.
- **Recorded effects:**
  - mail (`Mail::fake()`);
  - notifications;
  - queued jobs (`Queue::fake()`);
  - outgoing HTTP (`Http::fake()`, Laravel 7+; no real requests are sent);
  - insert, update and delete queries with their bindings;
  - events of the application namespace.
- **Recorded errors:** reported exceptions, logged errors, deprecations.

The report masks absolute paths of the two code bases and the PHP version strings.

## Configuration

`config/behavior-diff.php`:

| Key | Default | |
|---|---|---|
| `cases` | `behavior-diff/cases.json` | Requests file, relative to the project root |
| `routes.only` / `routes.except` | dev tools excluded | URI patterns (`api/*`) |
| `routes.methods` | GET, POST, PUT, PATCH, DELETE | |
| `users` | `null`: the first user | Ids to call protected routes as: `[1, 42]` for an admin and a customer |
| `guards` | `['web']` | Guards the user is set on |
| `values` | `[]` | Known values per field name; the first is used by the base request |
| `max_cases_per_endpoint` | 40 | |
| `connections` | `[null]` | Connections wrapped in a transaction (`null` is the default) |
| `fake.mail` / `.notifications` / `.queue` / `.http` | `true` | |
| `record_events` / `record_queries` | `true` | |
| `app_namespace` | detected | Events of this namespace are recorded |
| `now` / `seed` | `2024-01-15 12:00:00` / `1` | Frozen clock and random seed |
| `config` | debug off, array session and cache | Config overridden during the run |
| `reset` | `null` | Shell command run before the run: `php artisan migrate:fresh --seed --force` |
| `masks` | `[]` | Extra regex => replacement applied before comparing |

## Hand-written scenarios

`cases.json` can be edited; `scan` keeps hand-written cases (no `label`, or with a `scenario`). Cases of one scenario share cookies and run in order:

```json
{"id": "cart-1", "scenario": "cart", "method": "POST", "uri": "/cart", "body": {"product_id": 7}, "user": 1},
{"id": "cart-2", "scenario": "cart", "method": "POST", "uri": "/checkout", "user": 1}
```

The transaction is per request, so data written by one step of a scenario is rolled back before the next one. Session state (cart in the session) carries over; database state does not.

## Limitations

- **Implicit commits:** DDL on MySQL and code that commits explicitly end the transaction early; such writes stay. Restore the data with `reset`.
- **Process exits:** `dd()`, `exit` and `die` in the application stop the run.
- **State outside the database:** Redis, files and real queues are not rolled back. The array session and cache drivers are set by default.
- **Explicit route bindings** (`Route::model()`, `Route::bind()`) and closure-based bindings get no real keys; add them to `values`.
- **Rules that depend on the request** (`Rule::unique()->ignore($this->route('post'))`) are read without route parameters. If reading fails, the route still gets `$request->input()` fields.

## License

MIT
