# 17 — Docker Infrastructure

Five services, one command.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

API on `http://localhost:8000/api/v1`.

> **Status:** the Compose stack and Dockerfile are written to spec but have not
> been built and run — Docker Desktop was not installed on the machine this was
> developed on. The application itself is verified: 141 tests pass and the
> migrations and seeder run clean against a local PHP.

## Files

| File | Role |
| --- | --- |
| `docker-compose.yml` | Service definitions |
| `docker/Dockerfile` | PHP-FPM image |
| `docker/entrypoint.sh` | Startup orchestration |
| `docker/nginx/default.conf` | Web server |
| `docker/php/php.ini` | Runtime tuning |

---

## The five services

| Service | Image | Role |
| --- | --- | --- |
| `nginx` | nginx:1.27-alpine | HTTP entry point, forwards PHP to php-fpm |
| `app` | custom | PHP 8.3-FPM running the application |
| `mysql` | mysql:8.4 | Primary datastore |
| `redis` | redis:7-alpine | Cache **and** queue broker |
| `queue-worker` | same image as `app` | Background job processor |

---

## Design decisions

### 1. `queue-worker` shares the `app` image

```yaml
queue-worker:
  build:
    context: .
    dockerfile: docker/Dockerfile      # identical to `app`
  command: php artisan queue:work redis --queue=default --tries=3 --backoff=10 --max-time=3600 --sleep=1
```

Same image, different command. This guarantees the worker can **never** run a
different revision of the code than the web tier — a genuinely nasty class of bug
when a deploy updates one and not the other, and jobs start failing on a schema
the worker has not seen.

`--max-time=3600` recycles the process hourly so a long-lived PHP worker cannot
leak memory indefinitely.

### 2. Healthchecks gate startup

```yaml
mysql:
  healthcheck:
    test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-p${DB_ROOT_PASSWORD:-root}"]
    interval: 5s
    timeout: 5s
    retries: 20

app:
  depends_on:
    mysql:
      condition: service_healthy
    redis:
      condition: service_healthy
```

`depends_on` alone only waits for the container to **start**, not for MySQL to
accept connections. Without `condition: service_healthy`, a cold `docker compose
up` races the database and the app crashes on its first query.

### 3. The entrypoint waits anyway

```bash
until php -r 'new PDO(...);' 2>/dev/null; do
    echo "Waiting for MySQL..."
    sleep 2
done
```

Compose healthchecks cover a cold `up`. They do **not** cover a container
restarted on its own (`docker restart app`), where Compose makes no ordering
guarantee at all. Belt and braces.

### 4. Only the web container migrates

```bash
if [ "${1:-}" = "php-fpm" ]; then
    php artisan migrate --force
    php artisan storage:link || true
fi

exec "$@"
```

Both `app` and `queue-worker` run this entrypoint. If both migrated, they would
race — two processes running `migrate` simultaneously can deadlock or
double-apply. Branching on the command means exactly one container owns schema
migration.

### 5. Dependencies install in their own layer

```dockerfile
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize
```

Docker caches layers. Copying only `composer.json` and `composer.lock` first
means a source-only change does **not** invalidate the slow `composer install`
layer — the difference between a 5-second rebuild and a 90-second one.

`--no-autoloader` then `dump-autoload --optimize` after the source arrives,
because the autoloader map needs the application classes to exist.

### 6. PHP extensions, each with a reason

```dockerfile
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql bcmath intl zip opcache pcntl
```

| Extension | Why |
| --- | --- |
| `pdo_mysql` | Database driver |
| `opcache` | Bytecode cache — the single biggest PHP throughput win |
| `pcntl` | Lets `queue:work` handle `SIGTERM` and finish the job in hand instead of being killed mid-write on a deploy |
| `bcmath` | Available for downstream code; `Money` itself uses integer maths |
| `intl` | Locale-aware formatting |
| `zip` | Composer archive extraction |

`$PHPIZE_DEPS` is installed for the build and removed in the same `RUN` layer, so
the compiler toolchain never ships in the final image.

### 7. Redis persists

```yaml
redis:
  command: redis-server --appendonly yes
  volumes:
    - redis-data:/data
```

A cache-only Redis would not need AOF. This instance is **also the queue broker**,
so a restart without persistence would silently drop every pending order
confirmation.

The cache uses Redis database `1` and the queue database `0`, so
`php artisan cache:clear` cannot destroy queued jobs.

### 8. nginx serves JSON and nothing else

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "no-referrer" always;
add_header Content-Security-Policy "default-src 'none'; frame-ancestors 'none'" always;

location ~ /\.(?!well-known).* {
    deny all;
}
```

This host serves JSON only, so a maximally strict CSP costs nothing and blocks
any attempt to render a response as a document. The dotfile rule means `.env` and
`.git` are unreachable even if the document root is ever misconfigured.

```nginx
fastcgi_read_timeout 60s;
```

Long enough for a slow checkout under lock contention; short enough that a wedged
request cannot hold a php-fpm worker forever.

### 9. nginx mounts the source read-only

```yaml
volumes:
  - ./:/var/www/html:ro
```

nginx only needs to read `public/`. Read-only removes any possibility of the web
server writing to the application directory.

### 10. Errors never render into a response

```ini
display_errors = Off
log_errors = On
error_log = /dev/stderr
```

The exception handler owns the response body; the log owns the detail. Writing to
`/dev/stderr` means `docker compose logs` shows everything without a log volume.

```ini
opcache.validate_timestamps = 1
```

On for local development so code changes take effect. Set it to `0` in a real
production image — and redeploy the image to release code — for the full opcache
win.

---

## Common commands

```bash
docker compose up -d --build          # start
docker compose ps                     # status
docker compose logs -f app            # application logs
docker compose logs -f queue-worker   # worker logs
docker compose exec app bash          # shell

docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
docker compose exec app php artisan queue:failed
docker compose exec app php artisan cache:clear

docker compose exec mysql mysql -ulibyamarket -psecret libyamarket
docker compose exec redis redis-cli

docker compose down                   # stop
docker compose down -v                # stop and delete all data
```

---

## Port mapping

| Service | Host | Container |
| --- | --- | --- |
| nginx | `8000` | 80 |
| mysql | `3307` | 3306 |
| redis | `6380` | 6379 |

MySQL and Redis use non-standard host ports (`3307`, `6380`) so the stack does not
collide with a local MySQL or Redis already running on the default ports. All are
overridable:

```env
APP_PORT=8080
DB_FORWARD_PORT=3308
REDIS_FORWARD_PORT=6381
```

---

## Without Docker

```bash
php composer.phar install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan queue:work redis
```

Set `DB_HOST=127.0.0.1` and `REDIS_HOST=127.0.0.1` in `.env`, since `mysql` and
`redis` are Compose service names that only resolve inside the Docker network.
