# Mage AI Studio API Backend API

Laravel 10 API that powers uploads, video production, AI studio experiences for remixing existing or newly generated material, and GPU resource credit workflows for the clients.

## What’s inside

- JWT-authenticated APIs with social login and password recovery.
- Video workflows for vid2vid and Deforum: upload media, submit parameters, extend a run, finalize, track progress (with optional soundtrack uploads for finished videos), and inspect queue placement and live processing stats.
- Catalog and GPU resource credit primitives (categories, products, orders, wallets, finance operations).
- Administration endpoints for user management, finance operations, support requests, and content oversight.
- AI studio flows centered on ingesting existing media and combining it with generated assets to deliver publish-ready content.
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

- `POST /api/upload` — upload source media with `attachment`, optional `soundtrack` (mp3/aac/wav), and a required `type` of `vid2vid` or `deforum`. This creates a pending `video_jobs` record and stores the media.
- `POST /api/generate` — start generation for an existing uploaded job. Requires `videoId` and `type` (`vid2vid` or `deforum`), plus type-specific parameters:
  - `vid2vid`: `modelId`, `cfgScale`, `prompt`, `frameCount`, `denoising`, optional `seed`.
  - `deforum`: `modelId`, `prompt`, `preset`, `length`, optional `frameCount`, optional `extendFromJobId` to extend from a finished deforum job using its saved settings and last frame as the init image.
- `POST /api/finalize` — approve and enqueue vid2vid or deforum jobs for processing (moves from `pending` to `approved` and pushes to the configured queue based on the job's \`generator\` field).
- `POST /api/cancelJob/{videoId}` — cancel a pending or processing job and reset progress.
- `GET /status/{videoId}` — check job status, progress, queue snapshot, and full generation parameters for a single job.
- `GET /api/queue` — list all jobs for the authenticated user (their personal queue view, including status and progress).
- `GET /api/video-jobs/processing/status` — live overview of in-flight jobs (processing + queue) and global counts.
- `GET /api/video-jobs/processing/queue` — normalized queue feed for the authenticated user including placement and ETA.
### GPU resource credits & messaging

- Categories and GPU credit products: `GET /api/categories`, `GET /api/categories/{id}`, `GET /api/products`, `GET /api/products/{productId}`,
  plus authenticated CRUD under `/api/products`.
- Orders and finance: `/api/orders/*`, `/api/user-wallets`, `/api/wallet-types`, `/api/finance-operations`.
- Chats and messages: `/api/chats/*`, `/api/messages/*` (auth).
- Support tickets: `/api/support-request` and `/api/support-requests/*`.

### Administration

Routes under `/api/administration` are protected by `AuthorizationChecker` and `IsAdministratorChecker` and power the admin panel. Core areas include:

- User management: list users, reset passwords, and update account details.
- Finance oversight: review and change finance operation status, review orders, and manage wallet types.
- Support operations: search and update support requests and associated messages.

## Common tooling

- Run tests: `./vendor/bin/phpunit`
- Generate API docs (Scribe): `php artisan scribe:generate`
- Clear logs: `php artisan log:clear`

Xdebug is available in the Docker environment; point your IDE to the running container and use `php artisan tinker` for quick
REPL-style checks.

## DeforumationQT web console

- Visit `/deforumation-qt` to use the JavaScript port of DeforumationQT. Paste your JWT token, steer deforum payloads via `/api/generate`, and monitor processing/queue status in real time using the new endpoints above.
