# Vimage Backend API

A Laravel 10 backend that powers the Vimage service, providing authentication, media generation workflows, financial operations, and support tooling for the front-end clients.

## Features

- JWT-based authentication flows, including social login callbacks and password recovery.
- Video generation job lifecycle: upload source media, submit processing parameters, finalize jobs, and track status.
- Catalog management for categories, products, and properties with role-aware administration routes.
- Messaging between users (chats and direct messages) plus support request ticketing.
- Wallets, orders, finance operations, and user ratings to support marketplace-style transactions.

## Tech Stack

- PHP 8.2+, Laravel 10
- MySQL, Redis, Beanstalkd queues (via Pheanstalk)
- FFmpeg (via `pbmedia/laravel-ffmpeg` and `php-ffmpeg/php-ffmpeg`)
- Vite build tooling for assets

## Prerequisites

- PHP 8.2+
- Composer 2
- Node.js 18+ and npm
- MySQL or MariaDB
- Redis (for queues/cache) and Beanstalkd if you run the queue workers locally
- FFmpeg binaries available on the host

## Quick Start (Docker)

The repository includes a Docker-based workflow for parity with production:

```bash
cp .env.example .env
# Start database, cache, and app containers
docker-compose up -d
# Install backend dependencies
docker-compose exec app composer install
# Generate keys and secrets
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan jwt:secret
# Prepare the database
docker-compose exec app php artisan migrate:fresh --seed
```

Run a queue worker for background jobs (video generation, emails, etc.):

```bash
docker-compose exec app php artisan queue:work beanstalkd
```

## Local Development (without Docker)

```bash
cp .env.example .env
composer install
npm install
npm run build
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed
```

Make sure your `.env` is configured with database credentials, Redis connection details, and queue names (`HIGH_PRIORITY_QUEUE`, `MEDIUM_PRIORITY_QUEUE`, `LOW_PRIORITY_QUEUE`). FFmpeg paths can be customized via the `FFMPEG_PATH` and `FFPROBE_PATH` environment variables if needed.

## Common Commands

- Clear logs: `php artisan log:clear`
- Generate IDE helpers: `php artisan ide-helper:generate && php artisan ide-helper:models -N && php artisan ide-helper:meta`
- Run backend tests: `phpunit` (or `./vendor/bin/phpunit`)
- Build assets: `npm run build`
- API documentation (Scribe): `php artisan scribe:generate`

## API Overview

### Authentication

| Method | Path | Description |
| --- | --- | --- |
| POST | `/api/auth/register` | Register a new user |
| POST | `/api/auth/login` | Login with email/password |
| POST | `/api/auth/verified-email` | Confirm email verification |
| POST | `/api/auth/forgot-password` | Request reset link |
| POST | `/api/auth/reset-password` | Reset password with token |
| POST | `/api/auth/logout` | Logout (auth:api) |
| GET  | `/api/auth/me` | Retrieve authenticated profile |
| GET  | `/{provider}/auth` & `/{provider}/callback` | Socialite login flows |

**API v2 auth:** `/api/v2/login`, `/api/v2/logout`, `/api/v2/register`, `/api/v2/password-forgot`, `/api/v2/password-reset`, `/api/v2/me`.

### Video Generation

| Method | Path | Description |
| --- | --- | --- |
| POST | `/api/upload` | Upload source media (vid2vid/deforum) |
| POST | `/api/submit` | Submit vid2vid job parameters |
| POST | `/api/submitDeforum` | Submit deforum job parameters |
| POST | `/api/finalize` | Approve and enqueue vid2vid job |
| POST | `/api/finalizeDeforum` | Approve and enqueue deforum job |
| POST | `/api/cancelJob/{videoId}` | Cancel a pending job |
| GET  | `/api/status/{videoId}` | Check job progress |

Uploads require `attachment` plus a `type` of `vid2vid` or `deforum`. Authenticated users own their jobs; subsequent actions validate ownership before enqueuing work.

### Catalog & Marketplace

| Method | Path | Description |
| --- | --- | --- |
| GET | `/api/categories` | List categories |
| GET | `/api/categories/{id}` | Category details |
| GET | `/api/categories/by-user-id/{userId?}` | Categories with products for a user (auth) |
| GET | `/api/products` | Products by category |
| GET | `/api/products/{productId}` | Product detail |
| POST/PUT/DELETE | `/api/products` | Manage products (auth) |
| GET | `/api/orders/purchases` & `/api/orders/sales` | Order history |
| POST | `/api/orders` | Create order |
| PATCH | `/api/orders/confirm-order` | Confirm order |

Wallets and finance operations live under `/api/user-wallets`, `/api/wallet-types`, and `/api/finance-operations` with authenticated access and admin-only aggregations at `/api/administration`.

### Messaging & Support

| Method | Path | Description |
| --- | --- | --- |
| GET | `/api/chats/get-chats-by-current-user` | Chats for authenticated user |
| GET | `/api/chats/get-chat-by-user-id/{userId}` | Locate/create chat with a user |
| POST | `/api/chats` | Create chat |
| GET | `/api/messages/get-messages-by-chat-id/{chatId}` | Messages for a chat |
| POST | `/api/messages` | Send a message |
| POST | `/api/support-request` | Submit support request |
| POST/GET/PATCH | `/api/support-requests...` | Retrieve and update support tickets (auth) |

### Administration

Admin-only routes (behind `AuthorizationChecker` and `IsAdministratorChecker`) provide user management, finance oversight, and password resets under the `/api/administration` prefix.

## Project Structure

- `app/Http/Controllers` – HTTP controllers, including JSON:API resources and video job orchestration.
- `app/Models` – Eloquent models such as `Videojob` and catalog entities.
- `routes/api.php` – Route definitions for all API endpoints.
- `resources/` – Front-end assets built with Vite.

## Debugging

Xdebug is available in the Docker environment; configure your IDE to listen for connections. Use `php artisan tinker` for quick REPL-style debugging.

