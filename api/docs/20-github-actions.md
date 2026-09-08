# 20 — GitHub Actions: the CI pipeline

> A line-by-line walkthrough of `.github/workflows/laravel-ci.yml` as it exists
> in the repository, read on **2026-09-08**.
> Repository: `ayobmilad1812001-sys/Souqq`

---

## What GitHub Actions does here

It runs your commands **on a GitHub-hosted machine** every time something
happens in the repository — a push, or a pull request.

The point is not automation for its own sake. It is that the runner starts from
**nothing**: no local `.env`, no installed extension you forgot about, no
database left over from yesterday. Code that only works because of something
sitting on your laptop fails here, which is exactly what you want it to do.

---

## When it runs

```yaml
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
```

Two triggers: a push straight to `main`, and any pull request targeting `main`.
Pushes to other branches run nothing — the workflow exists to protect `main`.

---

## The monorepo line

```yaml
defaults:
  run:
    working-directory: api
```

This repository is a monorepo: the API lives in `api/`, the SPA in `web/`. This
makes every `run` step execute inside `api/`.

Without it, each step would need `cd api && …`, or would simply fail —
`composer.json` is not at the repository root.

> It applies to `run` steps only, **not** to `uses`. That is why
> `actions/checkout` still operates from the root and checks out the whole
> repository, which is what you want.

---

## The MySQL service container

```yaml
services:
  mysql:
    image: mysql:8.0
    env:
      MYSQL_DATABASE: libyamarket
      MYSQL_USER: libyamarket
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: root
    ports:
      - 3306:3306
    options: >-
      --health-cmd="mysqladmin ping -h 127.0.0.1 -u root -proot"
      --health-interval=10s
      --health-timeout=5s
      --health-retries=5
```

GitHub starts a MySQL container alongside the job, pre-creating a database and
user that match the values already in `.env.example` — which is why the
connection works without editing any credentials.

**The health check is not decoration.** MySQL takes several seconds to accept
connections after its container starts. Without it, the `migrate` step would
sometimes run before the database was listening, and CI would fail *randomly*.
That is the worst class of failure, because re-running it succeeds and you
conclude you fixed something.

Current settings poll every 10s with 5 retries — up to 50 seconds of grace.

---

## The two most important lines in the file

```yaml
- name: Copy environment file
  run: cp .env.example .env

- name: Configure database for CI
  run: |
    sed -i 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' .env
```

### Why `127.0.0.1` and not `mysql`

This is the real difference between your machine and the runner:

| Environment | What runs Laravel | Correct host |
| --- | --- | --- |
| Docker Compose, locally | a container on Docker's network | `mysql` (the service name) |
| GitHub Actions | **the runner itself**, not a container | `127.0.0.1` (a published port) |

Under Compose, containers reach each other by service name because Docker
provides internal DNS. On the runner, Laravel executes **directly on the host**
while MySQL sits in a container that published `3306:3306` — so the only address
Laravel can see is `127.0.0.1`.

`sed` rewrites that one line in `.env` after copying it, leaving everything else
untouched.

> This is the practical payoff of never committing `.env`: every environment
> builds its own from `.env.example`, and each can differ where it must.

---

## The remaining steps

| # | Step | What it does |
| --- | --- | --- |
| 1 | `actions/checkout@v4` | Clones the repository onto the runner |
| 2 | `shivammathur/setup-php@v2` | Installs PHP 8.4 with `mbstring, pdo, pdo_mysql` |
| 3 | `cp .env.example .env` | Creates the config file |
| 4 | `sed … DB_HOST` | Points it at `127.0.0.1` |
| 5 | `composer install` | Installs dependencies |
| 6 | `php artisan key:generate` | Creates `APP_KEY` — encryption fails without it |
| 7 | `php artisan migrate --force` | Builds the schema **on real MySQL** |
| 8 | `php artisan test` | Runs the suite |

`--force` is required because `migrate` refuses to run unattended in an
environment it believes is production, and there is nobody to type "yes" on a
CI runner.

If any step exits non-zero, everything after it is skipped and the run goes red.

---

## ⚠️ Important finding: the tests do not run on MySQL

This is the single most useful thing in this document.

The workflow starts MySQL and migrates against it — and then the test suite
**ignores it entirely**.

The reason is in `phpunit.xml`:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

PHPUnit sets these environment variables **before** Laravel boots and reads
`.env`, and Dotenv does not overwrite a variable that already exists. So
`phpunit.xml` always wins, and all 141 tests execute against **in-memory
SQLite**, not the MySQL service.

### What this means in practice

**The CI is still doing real work.** `migrate --force` genuinely runs against
MySQL, which catches MySQL-specific migration problems: column types, foreign
key definitions, index length limits. That is worth having.

**But `lockForUpdate()` is never exercised.** SQLite ignores it silently, so
`two_customers_cannot_both_buy_the_last_unit` proves the *logic* of oversell
prevention and not the *blocking behaviour* of the lock — which is the most
important mechanism in the codebase.

### The fix

Add a second run of the suite with the connection overridden at the process
level:

```yaml
- name: Run tests against MySQL
  env:
    DB_CONNECTION: mysql
    DB_HOST: 127.0.0.1
    DB_DATABASE: libyamarket
    DB_USERNAME: libyamarket
    DB_PASSWORD: secret
  run: php artisan test
```

Variables set in `env:` exist in the process environment before PHPUnit starts,
and PHPUnit will not overwrite them unless the XML entry uses `force="true"`.
The same suite then runs on both engines.

---

## Three other gaps

### 1. `web/` is never checked at all 🔴

Because of `working-directory: api`, **no step ever touches the `web/`
directory**.

A TypeScript error, a broken import, or a failing Vite build all pass with a
**green** badge. The CI is giving you confidence about half the repository while
silently ignoring the other half.

The fix is a second job, which runs in parallel and costs no extra wall-clock
time:

```yaml
  web:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: web
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: web/package-lock.json
      - run: npm ci
      - run: npx tsc -b        # type-check
      - run: npm run build     # must actually build
```

### 2. No Composer caching

`composer install` re-downloads everything on every run.

```yaml
- name: Cache Composer
  uses: actions/cache@v4
  with:
    path: api/vendor
    key: composer-${{ hashFiles('api/composer.lock') }}
```

### 3. Version drift between CI and Compose

| | CI | docker-compose |
| --- | --- | --- |
| MySQL | **8.0** | **8.4** |

Testing on 8.0 and deploying on 8.4 leaves room for a behavioural difference to
slip through. Pin both to `8.4`.

---

## Reading a failed run

1. Open the **Actions** tab
2. Click the red run
3. Click the `tests` job
4. The failing step is expanded automatically, marked ❌

Common failures:

| Message | Cause |
| --- | --- |
| `Connection refused [127.0.0.1:3306]` | MySQL was not ready — check `health-cmd` |
| `No application encryption key` | `key:generate` failed or was removed |
| `Access denied for user` | `.env.example` credentials do not match the service `env:` |
| `Could not open input file: artisan` | Wrong `working-directory` |

---

## The badge

```markdown
[![Laravel CI](https://github.com/ayobmilad1812001-sys/Souqq/actions/workflows/laravel-ci.yml/badge.svg)](https://github.com/ayobmilad1812001-sys/Souqq/actions/workflows/laravel-ci.yml)
```

It reflects the **latest run on `main`** only, not other branches.

---

## Suggested workflow now that CI exists

Stop pushing straight to `main`:

```bash
git checkout -b feature/password-reset
# ... work ...
git add -A && git commit -m "Add password reset endpoints"
git push -u origin feature/password-reset
```

Open a pull request. CI runs against it, and you see the result **before**
merging rather than after. You can enforce this from
`Settings → Branches → Add rule`, requiring the check to pass before merge.

---

## Assessment

| Aspect | Verdict |
| --- | --- |
| Overall workflow structure | ✅ Correct and clean |
| `working-directory` for the monorepo | ✅ The right solution |
| MySQL service with a health check | ✅ Prevents flaky failures |
| `DB_HOST` handling | ✅ Shows real understanding of the two environments |
| Migrations against real MySQL | ✅ Genuine value |
| Tests against MySQL | ❌ Actually run on SQLite |
| Any check on `web/` | ❌ Does not exist |
| Dependency caching | ⚠️ Missing — slower than it needs to be |
| MySQL version parity | ⚠️ 8.0 vs 8.4 |

**The foundation is sound.** This file was written with understanding rather
than copied — the `working-directory` line and the `DB_HOST` rewrite are both
things people commonly get wrong. The three items above are improvements on top
of something correct, not repairs to something broken.
