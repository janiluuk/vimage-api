# Vimage Backend API

Laravel 10 API that powers uploads, video production, and marketplace features for the clients.

## What’s inside

- JWT-authenticated APIs with social login and password recovery.
- Video workflows for vid2vid and Deforum: upload media, submit parameters, extend a run, finalize, and track progress.
- Catalog and marketplace primitives (categories, products, orders, wallets, finance operations).
- Messaging, chats, and support tickets.

## Requirements

- PHP 8.2+, Composer 2
- Node.js 18+ and npm
- MySQL/MariaDB and Redis
- Beanstalkd (queue) and FFmpeg binaries available on the host

## Setup

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed
npm run build
```

Docker users can start the stack with `docker-compose up -d` and run the same artisan commands inside the `app` container. Queue
workers should target the configured names (`HIGH_PRIORITY_QUEUE`, `MEDIUM_PRIORITY_QUEUE`, `LOW_PRIORITY_QUEUE`):

```bash
php artisan queue:work beanstalkd --queue=critical,medium,low
```

## API quick reference

### Auth

`POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout` (auth), `POST /api/auth/reset-password`, `GET /api/auth/me`.
API v2 mirrors these at `/api/v2/*` plus `/api/v2/me`.

### Video jobs

- `POST /api/upload` — upload source media with `attachment` and `type` of `vid2vid` or `deforum`.
- `POST /api/submit` — submit vid2vid parameters (`modelId`, `cfgScale`, `prompt`, `frameCount`, `denoising`).
- `POST /api/submitDeforum` — submit deforum parameters (`modelId`, `prompt`, `preset`, `length`, optional `frameCount`).
- `POST /api/submitDeforum` with `extendFromJobId` — extend a finished deforum job using its saved settings and last frame as the
  init image.
- `POST /api/finalize` / `POST /api/finalizeDeforum` — approve and enqueue jobs for processing.
- `POST /api/cancelJob/{videoId}` — cancel a pending job.
- `GET /api/status/{videoId}` — check job progress.

### Marketplace & messaging

- Categories and products: `GET /api/categories`, `GET /api/categories/{id}`, `GET /api/products`, `GET /api/products/{productId}`,
  plus authenticated CRUD under `/api/products`.
- Orders and finance: `/api/orders/*`, `/api/user-wallets`, `/api/wallet-types`, `/api/finance-operations`.
- Chats and messages: `/api/chats/*`, `/api/messages/*` (auth).
- Support tickets: `/api/support-request` and `/api/support-requests/*`.

### Administration

Routes under `/api/administration` are protected by `AuthorizationChecker` and `IsAdministratorChecker` for user management and
financial oversight.

## Common tooling

- Run tests: `./vendor/bin/phpunit`
- Generate API docs (Scribe): `php artisan scribe:generate`
- Clear logs: `php artisan log:clear`

Xdebug is available in the Docker environment; point your IDE to the running container and use `php artisan tinker` for quick
REPL-style checks.
