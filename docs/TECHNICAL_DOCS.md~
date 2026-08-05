# Sales-Spy — Internal Technical Documentation
### Backend Engineering Reference Guide

> **Who this document is for:** Any developer joining the Sales-Spy backend team. Read this entire document before writing a single line of code. Everything you need to understand the system, contribute correctly, and avoid breaking things is here.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture Overview](#2-architecture-overview)
3. [Tech Stack & Why](#3-tech-stack--why)
4. [Local Development Setup](#4-local-development-setup)
5. [Environment Variables Reference](#5-environment-variables-reference)
6. [Folder Structure](#6-folder-structure)
7. [Coding Standards](#7-coding-standards)
8. [Database Schema](#8-database-schema)
9. [API Conventions](#9-api-conventions)
10. [Authentication System](#10-authentication-system)
11. [Credits System](#11-credits-system)
12. [Payment System](#12-payment-system)
13. [Background Jobs & Scheduling](#13-background-jobs--scheduling)
14. [The Discovery Engine — Scraper Stack](#14-the-discovery-engine--scraper-stack)
15. [Deployment — Railway](#15-deployment--railway)
16. [Adding a New Feature — Step by Step](#16-adding-a-new-feature--step-by-step)
17. [Common Mistakes to Avoid](#17-common-mistakes-to-avoid)
18. [Security Rules](#18-security-rules)
19. [External Services Reference](#19-external-services-reference)

---

## 1. Project Overview

Sales-Spy is a **B2B SaaS lead intelligence platform** with two core products:

**Websites Module** — Discovers and indexes any website regardless of type (photography studios, car repair shops, restaurants, portfolios, agencies). Target users are developers and freelancers who use this data to find potential clients to pitch their services to.

**E-commerce Module** — Discovers and indexes online stores specifically (Shopify, WooCommerce, Wix stores, etc.). Target users are sales teams pitching apps, services, fulfilment, and marketing to store owners.

**The backend is responsible for:**
- Storing a large database of discovered websites and e-commerce stores
- Serving that data through a filtered, paginated REST API
- Managing user accounts, subscriptions, and a credit-based usage system
- Processing payments (manual crypto TRC20 USDT)
- Running background jobs that continuously discover and refresh website data
- Providing an admin interface for payment verification and user management

**The frontend** is a React SPA hosted separately. It communicates with this API exclusively. The frontend developer never touches this codebase.

---

## 2. Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                    FRONTEND (React)                      │
│              sales-spy.onrender.com                     │
└─────────────────────┬───────────────────────────────────┘
                      │ HTTPS — Bearer Token
                      ▼
┌─────────────────────────────────────────────────────────┐
│                  LARAVEL API (PHP 8.3)                   │
│           sales-spy-api.up.railway.app                  │
│                                                         │
│  ┌──────────┐  ┌───────────┐  ┌────────────────────┐  │
│  │ Routes   │  │ Middleware │  │   Controllers      │  │
│  │ api.php  │→ │ Sanctum   │→ │ (thin — delegates) │  │
│  └──────────┘  │ Throttle  │  └────────┬───────────┘  │
│                │ Admin     │           ▼               │
│                │ CORS      │  ┌────────────────────┐  │
│                └───────────┘  │   Services (fat)   │  │
│                               │ AuthService        │  │
│                               │ ProfileService     │  │
│                               │ PaymentService     │  │
│                               │ SubscriptionService│  │
│                               │ CreditService      │  │
│                               │ CloudinaryService  │  │
│                               │ ActivityService    │  │
│                               └────────┬───────────┘  │
└────────────────────────────────────────┼───────────────┘
                                         │
              ┌──────────────────────────┼──────────────┐
              ▼                          ▼              ▼
┌─────────────────┐         ┌──────────────────┐  ┌──────────┐
│  PostgreSQL      │         │  Redis (Upstash)  │  │Cloudinary│
│  (Neon)         │         │                  │  │          │
│  Primary store   │         │  Queue driver    │  │ Images   │
│  All user data   │         │  Cache driver    │  │ Avatars  │
│  Subscriptions  │         │  Rate limiting   │  │ Payment  │
│  Payment orders │         │  Sessions        │  │ proofs   │
└─────────────────┘         └──────────────────┘  └──────────┘

              ┌────────────────────────────────────────┐
              │         PYTHON SCRAPERS                │
              │    (Separate repository)               │
              │                                        │
              │  Discover websites → POST to API       │
              │  Runs on separate server/cron          │
              └────────────────────────────────────────┘
```

**Key architectural principle — Stateless API.** No server-side sessions. Every request carries a Sanctum Bearer token. This means multiple API instances can run simultaneously (horizontal scaling) because no server holds state between requests.

---

## 3. Tech Stack & Why

| Layer | Technology | Version | Reason |
|---|---|---|---|
| Language | PHP | 8.3 | Laravel requirement, modern features |
| Framework | Laravel | 13.x | Batteries-included, excellent ecosystem |
| Database | PostgreSQL | 16 (Neon) | Better JSONB support than MySQL, Render-native |
| Cache & Queue | Redis | (Upstash) | Fast queuing for background jobs, rate limiting |
| Auth | Laravel Sanctum | Latest | Simple stateless token auth for SPAs |
| OAuth | Laravel Socialite | Latest | Google and GitHub login |
| Permissions | Spatie Laravel Permission | v6+ | Role-based access control (admin vs user) |
| Query Building | Spatie Laravel Query Builder | Latest | Safe, clean filtering for search endpoints |
| File Storage | Cloudinary | PHP SDK | Free tier, no payment needed, auto-optimizes images |
| API Docs | Scribe | Latest | Auto-generates from controller docblocks |
| Deployment | Railway | — | Docker-based, auto-deploys on push to main |

**Python Scrapers (separate repository):**

| Layer | Technology | Reason |
|---|---|---|
| Language | Python | 3.11+ | Best ecosystem for web crawling and data processing |
| HTTP Client | httpx | Async, fast, modern replacement for requests |
| HTML Parsing | BeautifulSoup4 + lxml | Proven, fast, handles malformed HTML well |
| Task Queue | Celery + Redis | Distributed task processing, same Redis as Laravel |
| Scheduling | Celery Beat | Cron-like scheduling for discovery jobs |
| Data Storage | psycopg2 | Direct PostgreSQL writes from Python |
| Proxy Management | Rotating proxy service | Avoids IP bans during mass crawling |

---

## 4. Local Development Setup

**Prerequisites — install these first:**
- PHP 8.3+
- Composer
- PostgreSQL 15+
- Redis
- Node.js (LTS)

**Step 1 — Clone and install:**
```bash
git clone https://github.com/developerfaruq/sales-spy-api.git
cd sales-spy-api
composer install
```

**Step 2 — Environment setup:**
```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your local credentials. See Section 5 for all variables.

**Step 3 — Database:**
Create a local PostgreSQL database:
```bash
psql -U postgres -c "CREATE DATABASE sales_spy;"
```

Run migrations and seed:
```bash
php artisan migrate
php artisan db:seed
```

The seeder creates: admin and user roles, all four plans (free/basic/pro/enterprise), and default settings including the crypto wallet address.

**Step 4 — Make yourself admin:**
```bash
php artisan tinker
$user = \App\Models\User::where('email', 'your@email.com')->first();
$user->assignRole('admin');
```

**Step 5 — Start services:**

Terminal 1 — API server:
```bash
php artisan serve
```

Terminal 2 — Queue worker (required for background jobs):
```bash
php artisan queue:work
```

Terminal 3 — Scheduler (optional for local, runs cron jobs):
```bash
php artisan schedule:work
```

**Step 6 — Verify:**

Hit `GET http://localhost:8000/api/v1/health` in Postman. Should return:
```json
{
  "success": true,
  "message": "Sales-Spy API v1 is live",
  "data": { "version": "1.0.0", "environment": "local" }
}
```

API docs available at `http://localhost:8000/docs`.

---

## 5. Environment Variables Reference

```env
# ─── Application ─────────────────────────────────────────
APP_NAME="Sales-Spy API"
APP_ENV=local                          # local | production
APP_KEY=base64:...                     # Generated — never share
APP_DEBUG=true                         # MUST be false in production
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173     # React dev server URL

# ─── Database ────────────────────────────────────────────
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sales_spy
DB_USERNAME=postgres
DB_PASSWORD=your_password

# Or use a full URL (Railway/Neon format):
DATABASE_URL=postgresql://user:pass@host/dbname

# ─── Redis ───────────────────────────────────────────────
REDIS_CLIENT=predis                    # predis (local) | phpredis (production)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Or use a full URL (Upstash format):
REDIS_URL=rediss://default:password@host.upstash.io:6380

# ─── Queue & Cache ───────────────────────────────────────
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# ─── CORS ────────────────────────────────────────────────
ALLOWED_ORIGINS=*                      # Comma-separated list in production

# ─── File Storage ────────────────────────────────────────
FILESYSTEM_DISK=local
CLOUDINARY_URL=cloudinary://API_KEY:API_SECRET@CLOUD_NAME

# ─── OAuth ───────────────────────────────────────────────
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/api/v1/auth/google/callback

GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_REDIRECT_URI=http://localhost:8000/api/v1/auth/github/callback

# ─── Mail ────────────────────────────────────────────────
MAIL_MAILER=log                        # log (local) | resend (production)
MAIL_FROM_ADDRESS=noreply@sales-spy.com
MAIL_FROM_NAME="Sales-Spy"
RESEND_API_KEY=                        # Production only

# ─── Scribe Docs ─────────────────────────────────────────
SCRIBE_AUTH_KEY=                       # Sample token shown in docs
```

**Production-only variables (set in Railway dashboard, never in code):**
- `APP_DEBUG=false` — CRITICAL, never true in production
- `APP_KEY` — Copy from local .env, never regenerate in production
- `DATABASE_URL` — Neon direct connection URL (not pooler URL)
- `REDIS_URL` — Upstash Redis URL
- `PORT=8080` — Railway dynamic port

---

## 6. Folder Structure

```
sales-spy-api/
├── app/
│   ├── Console/
│   │   └── Commands/                  ← Artisan commands (cron jobs)
│   │       ├── ExpireSubscriptions.php
│   │       └── ExpirePaymentOrders.php
│   ├── Enums/                         ← PHP Enums for fixed value sets
│   │   ├── BillingCycle.php
│   │   ├── OAuthProviderEnum.php
│   │   ├── PaymentStatus.php
│   │   ├── SubscriptionStatus.php
│   │   └── UserPlan.php
│   ├── Exceptions/                    ← Custom exception classes
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/                 ← Admin-only endpoints
│   │   │   │   └── AdminUserController.php
│   │   │   ├── Auth/                  ← Registration, login, OAuth
│   │   │   │   └── AuthController.php
│   │   │   ├── Payment/               ← Payment orders, proof upload
│   │   │   │   └── PaymentController.php
│   │   │   ├── Store/                 ← Plans, websites, e-commerce
│   │   │   │   └── PlanController.php
│   │   │   └── User/                  ← Profile, settings, notifications
│   │   │       └── ProfileController.php
│   │   ├── Middleware/
│   │   │   ├── EnsureUserIsAdmin.php  ← Admin route protection
│   │   │   └── SecurityHeaders.php    ← Security headers on all responses
│   │   └── Requests/                  ← Form request validators
│   │       ├── Auth/
│   │       ├── Payment/
│   │       └── User/
│   ├── Models/                        ← Eloquent models
│   │   ├── NotificationPreference.php
│   │   ├── OAuthProvider.php
│   │   ├── PaymentOrder.php
│   │   ├── Plan.php
│   │   ├── Setting.php
│   │   ├── Subscription.php
│   │   ├── User.php
│   │   └── UserActivity.php
│   ├── Providers/
│   │   └── AppServiceProvider.php     ← Service container bindings
│   └── Services/                      ← ALL business logic lives here
│       ├── ActivityService.php
│       ├── AuthService.php
│       ├── CloudinaryService.php
│       ├── PaymentService.php
│       ├── ProfileService.php
│       └── SubscriptionService.php
├── bootstrap/
│   └── app.php                        ← Middleware registration, exception handler
├── database/
│   ├── migrations/                    ← Database schema history
│   └── seeders/
│       ├── DatabaseSeeder.php         ← Master seeder (runs all others)
│       ├── PlanSeeder.php             ← Seeds four plans
│       ├── RoleSeeder.php             ← Seeds admin and user roles
│       └── SettingSeeder.php          ← Seeds default settings
├── docker/
│   ├── nginx.conf                     ← Nginx config with dynamic PORT
│   └── start.sh                       ← Container startup script (written in Dockerfile)
├── routes/
│   ├── api.php                        ← ALL API routes defined here
│   └── console.php                    ← Scheduled commands
├── .scribe/
│   ├── auth.md                        ← Custom auth documentation (manual)
│   └── intro.md                       ← Custom intro documentation (manual)
└── Dockerfile                         ← Docker build definition for Railway
```

---

## 7. Coding Standards

### The Most Important Rule — Fat Services, Thin Controllers

**A controller has exactly three responsibilities:**
1. Receive the HTTP request
2. Pass data to a Service
3. Return the HTTP response

**A Service contains all business logic.** If you are thinking, it goes in a Service. Never put if-statements, database queries (beyond simple lookups), calculations, or external API calls in a controller.

**Wrong — logic in controller:**
```php
public function register(Request $request): JsonResponse
{
    $user = User::create([...]);
    $user->assignRole('user');
    Subscription::create(['user_id' => $user->id, ...]);
    // 20 more lines of logic
    return response()->json([...]);
}
```

**Correct — controller delegates to service:**
```php
public function register(RegisterRequest $request): JsonResponse
{
    $user  = $this->authService->register($request->validated());
    $token = $this->authService->generateToken($user);

    return $this->successResponse(
        data: ['token' => $token, 'user' => $this->formatUser($user)],
        message: 'Account created successfully',
        statusCode: 201
    );
}
```

---

### Response Format — Always Use BaseController Methods

Every response must use `successResponse()` or `errorResponse()` from the BaseController. Never manually build `response()->json([...])` arrays in controllers.

```php
// Success
return $this->successResponse(
    data: $someData,
    message: 'Something retrieved successfully',
    statusCode: 200,     // optional, defaults to 200
    meta: [...]          // optional, for pagination
);

// Error
return $this->errorResponse(
    message: 'Something went wrong',
    errors: $validationErrors,   // optional
    statusCode: 400
);
```

This guarantees the frontend always receives:
```json
{
  "success": true|false,
  "message": "...",
  "data": {...} | null,
  "errors": {...} | null,
  "meta": {...}           // only on paginated responses
}
```

---

### Naming Conventions

| Thing | Convention | Example |
|---|---|---|
| Controllers | PascalCase + Controller | `ProfileController` |
| Services | PascalCase + Service | `PaymentService` |
| Models | PascalCase singular | `PaymentOrder` |
| Migrations | snake_case descriptive | `create_payment_orders_table` |
| Routes | kebab-case | `/user/notification-preferences` |
| Request classes | PascalCase + Request | `InitiatePaymentRequest` |
| Enums | PascalCase | `PaymentStatus` |
| Variables | camelCase | `$paymentOrder` |
| Database columns | snake_case | `proof_image_url` |
| Scribe groups | Title Case | `@group Admin — Users` |

---

### Dependency Injection — Always Use Constructor Injection

Never instantiate services with `new` inside methods. Always inject through the constructor.

```php
// Wrong
public function someMethod(): JsonResponse
{
    $service = new PaymentService(); // ← Never do this
}

// Correct
public function __construct(
    protected PaymentService $paymentService
) {}
```

Register all services as singletons in `AppServiceProvider::register()`. This ensures one instance is shared across the entire request lifecycle.

---

### Enums — Use Them Everywhere for Fixed Values

Never use raw strings for status values, plan names, or any fixed set of options.

```php
// Wrong
$order->update(['status' => 'awaiting_verification']);
if ($order->status === 'approved') { ... }

// Correct
$order->update(['status' => PaymentStatus::AWAITING_VERIFICATION]);
if ($order->status === PaymentStatus::APPROVED) { ... }
```

---

### Null Safety — Always Guard Against Null

Every protected controller method must check `$request->user()` even though the middleware handles it. Defensive programming prevents issues in testing and Scribe generation.

```php
public function someProtectedMethod(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return $this->errorResponse('Unauthenticated.', statusCode: 401);
    }

    // rest of method
}
```

---

### Try-Catch — Only for External Service Calls

Do not wrap internal Laravel operations in try-catch. The global exception handler covers those. Only wrap calls to external services.

```php
// Needs try-catch (external service)
try {
    $result = $this->cloudinaryService->uploadImage($file);
} catch (\Exception $e) {
    return $this->errorResponse($e->getMessage(), statusCode: 500);
}

// Does NOT need try-catch (internal Eloquent)
$user = User::create([...]);  // Global handler covers this
```

External services that always need try-catch:
- `CloudinaryService` — image uploads and deletes
- Any `Http::get()` or `Http::post()` calls
- Stripe API calls (when added)
- Any third-party API integration

---

### Scribe Annotations — Required on Every Controller Method

Every public controller method must have a complete docblock for Scribe to generate accurate documentation.

```php
/**
 * Short title (becomes the endpoint title in docs)
 *
 * Longer description explaining what this does and when to use it.
 *
 * @authenticated          ← include if requires token
 * @unauthenticated        ← include if public
 * @group Plans            ← the sidebar group in docs
 *
 * @urlParam orderId integer required Description. Example: 1
 * @queryParam search string optional Description. Example: john
 * @bodyParam email string required Description. Example: john@example.com
 *
 * @response 200 { ... json example ... }
 * @response 422 { ... error example ... }
 */
public function methodName(): JsonResponse
```

After adding any new endpoint, always regenerate docs:
```bash
php artisan scribe:generate
```

---

### Git Commit Messages — Follow This Pattern

```
Phase X: Brief description of what was added

# Examples:
Phase 5: Manual crypto payment orders, proof upload, TXID submission
Fix: Sanctum guard circular reference causing infinite loop
Admin: User listing, search, filter, toggle status
Security: Add rate limiting and security headers middleware
```

---

## 8. Database Schema

### Tables Overview

| Table | Purpose |
|---|---|
| `users` | Core user accounts |
| `personal_access_tokens` | Sanctum API tokens |
| `oauth_providers` | Google/GitHub OAuth links |
| `roles`, `permissions` | Spatie permission tables |
| `plans` | Subscription plan definitions |
| `subscriptions` | User subscription tracking |
| `payment_orders` | Crypto payment orders |
| `settings` | Admin-configurable key-value settings |
| `notification_preferences` | Per-user notification toggles |
| `user_activities` | Activity log (login, exports, etc.) |
| `cache` | Laravel cache table |
| `jobs` | Queue job storage |

### Key Relationships

```
User ──── hasMany ──→ Subscription ──── belongsTo ──→ Plan
     ──── hasOne  ──→ activeSubscription
     ──── hasMany ──→ PaymentOrder ──── belongsTo ──→ Plan
     ──── hasMany ──→ OAuthProvider
     ──── hasOne  ──→ NotificationPreference
     ──── hasMany ──→ UserActivity
     ──── hasMany ──→ personal_access_tokens (via Sanctum)
```

### Important Column Notes

**`users.credits_balance`** — Current credit balance. Deducted on each search/export action. Reset monthly by scheduled job.

**`users.credits_monthly_quota`** — Credits allocated per month based on plan. Used by the reset job to know how many to restore.

**`plans.monthly_price`** — Stored in **cents** (integer). `$225.00` = `22500`. Always divide by 100 for display. Never store money as decimals.

**`plans.monthly_quota`** — `-1` means unlimited (Enterprise plan). Always check for -1 before deducting credits.

**`settings.key`** — Application settings stored as key-value. Access via `Setting::get('key', $default)`. Values are cached in Redis for 1 hour automatically.

**`payment_orders.reference`** — Human-readable order ID in format `SPY-YYYY-NNNNN`. Used in all communications with users.

---

## 9. API Conventions

### Base URL
```
Production: https://sales-spy-api-production.up.railway.app/api/v1
Local:      http://localhost:8000/api/v1
```

### Authentication
All protected endpoints require:
```
Authorization: Bearer {token}
```
Token is obtained from `POST /auth/register` or `POST /auth/login`.

### Response Format
Every response follows this exact structure:

```json
// Success
{
  "success": true,
  "message": "Human readable message",
  "data": { ... }
}

// Paginated
{
  "success": true,
  "message": "...",
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 25,
    "total": 243
  }
}

// Error
{
  "success": false,
  "message": "What went wrong",
  "errors": { "field": ["error message"] } | null
}
```

### HTTP Status Codes Used

| Code | When |
|---|---|
| 200 | Success |
| 201 | Resource created successfully |
| 400 | Bad request (invalid action, e.g., cancel free plan) |
| 401 | No token or invalid token |
| 402 | Insufficient credits |
| 403 | Valid token but insufficient permissions |
| 404 | Resource not found |
| 422 | Validation failed |
| 429 | Rate limit exceeded |
| 500 | Unexpected server error |

### Rate Limits

| Route Group | Limit |
|---|---|
| Public auth routes (register, login) | 20 requests per minute per IP |
| Protected routes (authenticated) | 120 requests per minute per user |
| Admin routes | 60 requests per minute per user |

### Pagination

All list endpoints support:
- `?page=1` — page number
- `?per_page=25` — results per page (max 100)

### HTTP Methods Used

| Method | Purpose |
|---|---|
| GET | Retrieve data (no side effects) |
| POST | Create new resource or trigger action |
| PATCH | Partial update (only send changed fields) |
| PUT | Full replacement (send all fields) |
| DELETE | Remove resource |

---

## 10. Authentication System

### Flow

```
Registration:
POST /auth/register → create user → assign 'user' role → 
assign free plan subscription → return Sanctum token

Login:
POST /auth/login → verify password → delete old tokens → 
create new token → return token

OAuth (Google/GitHub):
GET /auth/{provider}/redirect → redirect to provider
GET /auth/{provider}/callback → find or create user → 
create token → return token
```

### Token Management

Tokens are stored in `personal_access_tokens`. On every login, all old tokens for that user are deleted and a fresh one is created. This means logging in on a new device logs out all other devices.

On logout (`POST /auth/logout`), only the current token is deleted. Other sessions remain active.

On password change, all tokens except the current one are deleted, forcing re-login on other devices.

### Guard Configuration

**Critical — do not change these without understanding the implications:**

- `config/auth.php` — api guard uses `sanctum` driver
- `config/sanctum.php` — guard is `['web']` (NOT `['api']` — setting it to `api` causes infinite recursion)
- `User` model has `protected string $guard_name = 'api'` for Spatie Permissions

---

## 11. Credits System

### How Credits Work

Every user has a `credits_balance` on their `users` record. Credits are deducted when users access paid features (search results, store details, exports, deep scans).

**Credit costs (configured in settings):**

| Action | Cost |
|---|---|
| View store details | 1 credit |
| Search result (per result) | 1 credit |
| Export to CSV (per row) | 2 credits |
| Deep scan a store | 5 credits |

### Credit Deduction Pattern

All credit deduction goes through `CreditService::spend()`. Never deduct credits directly in a controller or another service.

```php
// Always check before deducting
if (! $user->hasCredits($cost)) {
    return $this->errorResponse(
        message: 'Insufficient credits. Please upgrade your plan.',
        statusCode: 402
    );
}

// Deduct
$this->creditService->spend($user, $cost, 'Store detail view');
```

### Monthly Credit Reset

A scheduled job runs on the 1st of each month and resets every active user's credits to their plan's monthly quota. Enterprise users (quota = -1) get 99999 credits (effectively unlimited).

---

## 12. Payment System

### Manual Crypto Flow (TRC20 USDT)

```
1. POST /payments/initiate
   → Creates PaymentOrder (status: pending)
   → Returns wallet address from settings table
   → Returns exact USDT amount

2. User sends USDT to wallet address

3. POST /payments/{id}/proof
   → Uploads transaction screenshot to Cloudinary
   → Stored under sales-spy/payment-proofs/

4. POST /payments/{id}/txid
   → User submits TronScan transaction hash
   → Status changes to: awaiting_verification
   → Admin is notified (Phase 14)

5. Admin verifies on TronScan manually
   PUT /admin/payments/{id}/review (Phase 14)
   → status: approved → SubscriptionService::activateSubscription()
   → status: rejected → rejection_reason stored
```

### Important Payment Rules

- **Wallet address** is never hardcoded. Always read from `Setting::get('crypto_wallet_address')`. Admin can change it without code deployment.
- **One pending order per user.** When a new order is initiated, any existing pending orders are automatically expired.
- **TXID validation.** Must be hexadecimal, minimum 20 characters. Regex: `/^[a-fA-F0-9]+$/`.
- **Screenshot required before TXID.** Cannot submit TXID without uploading a proof screenshot first.
- **Orders expire after 24 hours.** Hourly cron job marks pending orders as expired.
- **Order references** follow format `SPY-YYYY-NNNNN` for human-friendly support communication.

---

## 13. Background Jobs & Scheduling

### Scheduled Commands

| Command | Schedule | Purpose |
|---|---|---|
| `subscriptions:expire` | Daily at midnight | Downgrades users whose subscription period ended |
| `payments:expire` | Hourly | Marks 24h+ pending payment orders as expired |
| `websites:discover` | Every 6 hours (Phase 8) | Discovers new websites |
| `stores:refresh` | Weekly (Phase 8) | Re-crawls existing stores for updates |

All schedules are defined in `routes/console.php`.

### Queue Architecture

Queue driver is Redis (Upstash). Three queues are used:

| Queue | Priority | Purpose |
|---|---|---|
| `default` | High | Emails, notifications, user-facing operations |
| `exports` | Medium | CSV/XLSX export generation |
| `crawl` | Low | Background website discovery |

**On Railway**, the worker service runs `php artisan horizon` which processes all queues. Horizon dashboard is available at `/horizon` (admin only in production).

### Adding a New Background Job

```bash
php artisan make:job YourJobName
```

Inside the job class, implement `handle()`. Dispatch it with:
```php
YourJobName::dispatch($param)->onQueue('default');

// Or delay it
YourJobName::dispatch($param)->delay(now()->addMinutes(5));
```

---

## 14. The Discovery Engine — Scraper Stack

### Why Python and Not Laravel?

Web crawling at scale requires async I/O, massive parallelism, and rich data processing libraries. Python's ecosystem (httpx, BeautifulSoup4, Scrapy, Celery) is purpose-built for this. Laravel would work but with significantly more effort for the same result. The scrapers live in a **separate Python repository** and write directly to the same PostgreSQL database.

### Repository Structure (Separate Repo)

```
sales-spy-scrapers/
├── crawlers/
│   ├── website_crawler.py       ← Detects any website type
│   ├── shopify_crawler.py       ← Shopify-specific deep crawl
│   ├── woocommerce_crawler.py   ← WooCommerce detection
│   └── platform_detector.py    ← Identifies platform from HTTP headers/HTML
├── sources/
│   ├── common_crawl.py          ← Queries Common Crawl via Athena
│   ├── builtwith.py             ← BuiltWith API integration
│   ├── serp_api.py              ← Google search for site discovery
│   └── domain_feeds.py          ← Newly registered domain feeds
├── processors/
│   ├── contact_extractor.py     ← Extracts email, phone, social links
│   ├── platform_classifier.py   ← Classifies website type/niche
│   └── data_normalizer.py       ← Normalizes data before DB insert
├── tasks/
│   ├── celery_app.py            ← Celery configuration
│   ├── discovery_tasks.py       ← Celery tasks for discovery
│   └── refresh_tasks.py         ← Celery tasks for refreshing data
├── db/
│   ├── connection.py            ← PostgreSQL connection
│   └── repository.py            ← Database write operations
├── config/
│   └── settings.py              ← Configuration from environment
└── requirements.txt
```

### How Websites Are Detected

**Any website platform detection:**
- Wix — `X-Wix-Meta-Site-Id` header present
- WordPress — `/wp-json/` endpoint responds, or `wp-content` in HTML
- Squarespace — `generator` meta tag contains `Squarespace`
- Webflow — `data-wf-site` attribute in HTML
- Wix — `static.wixstatic.com` in page source

**E-commerce specific:**
- Shopify — `/products.json` endpoint returns valid JSON
- WooCommerce — `/wp-json/wc/v3/` endpoint exists
- Wix Stores — Wix site with `ecom.wix.com` in source

### Database Tables Populated by Scrapers

**`websites` table** — general websites of all types
**`ecommerce_stores` table** — specifically e-commerce stores

Both tables are read by the Laravel API but **only written to by the Python scrapers**. Laravel controllers only READ from these tables — they never write discovery data.

### Scraper to API Communication

The Python scrapers write directly to PostgreSQL. No API calls from scrapers to Laravel. This is intentional — direct DB writes are faster than HTTP and remove the Laravel API as a bottleneck during bulk ingestion.

However, for triggering re-crawls from the admin panel, the admin can call:
```
POST /api/v1/admin/crawl/trigger (Phase 14)
```
Which pushes a job onto the Redis `crawl` queue. The Python Celery worker monitors the same Redis and picks up the job.

---

## 15. Deployment — Railway

### Services on Railway

| Service | Type | Purpose |
|---|---|---|
| `sales-spy-api` | Web Service | Main Laravel API (Docker) |
| `sales-spy-worker` | Background Worker | Queue processing (Horizon) |
| `sales-spy-cron` | Cron Job | Laravel scheduler (every minute) |

### Deploy Process

Every push to the `main` branch automatically triggers a Railway deployment.

**Build process (Dockerfile):**
1. Pulls `php:8.3-fpm-alpine` base image
2. Installs system dependencies (nginx, git, gettext, etc.)
3. Installs PHP extensions (pdo_pgsql, redis, gd, zip, etc.)
4. Copies project files
5. Runs `composer install --no-dev`
6. Writes `start.sh` directly (avoids CRLF issues from Windows)

**Startup process (start.sh):**
1. Clears all caches
2. Runs `php artisan migrate --force`
3. Runs `php artisan db:seed --force` (idempotent — uses updateOrCreate)
4. Generates Scribe docs
5. Caches config, routes, views
6. Starts PHP-FPM
7. Starts Nginx on dynamic PORT (from Railway env var)

### IMPORTANT — Neon Database URL

Railway must use the **direct connection URL** from Neon, NOT the pooler URL.

```
# Wrong (has -pooler in hostname)
postgresql://user:pass@ep-dry-surf-ammq14xs-pooler.c-5.us-east-1.aws.neon.tech/neondb

# Correct (no -pooler)
postgresql://user:pass@ep-dry-surf-ammq14xs.c-5.us-east-1.aws.neon.tech/neondb
```

The pooler breaks Laravel migrations because it does not support the `BEGIN/COMMIT` transaction pattern that migrations use.

### Environment Variables on Railway

Set all variables in Railway dashboard → Service → Variables. Never commit secrets to git. See Section 5 for the full variable list.

---

## 16. Adding a New Feature — Step by Step

Follow this exact order every time you add a new feature. Skipping steps creates technical debt.

**Step 1 — Migration**
```bash
php artisan make:migration create_thing_table
# or
php artisan make:migration add_column_to_table
```
Always use `Schema::hasColumn()` guards when adding columns to existing tables.

**Step 2 — Model**
```bash
php artisan make:model Thing
```
Add `$fillable`, `casts()`, and relationships.

**Step 3 — Enum (if applicable)**
Create `app/Enums/ThingStatus.php` for any fixed set of string values.

**Step 4 — Service**
Create `app/Services/ThingService.php`. All logic goes here.
Register in `AppServiceProvider::register()`.

**Step 5 — Form Requests**
```bash
php artisan make:request Thing/CreateThingRequest
```
Include `bodyParameters()` method for Scribe.

**Step 6 — Controller**
```bash
php artisan make:controller Thing/ThingController
```
Thin controller. Call service. Format response. Full Scribe annotations on every method.

**Step 7 — Routes**
Add to `routes/api.php` in the correct middleware group (public/protected/admin).

**Step 8 — Test locally**
Run through all endpoints in Postman.

**Step 9 — Regenerate docs**
```bash
php artisan scribe:generate
```
Verify every new endpoint appears with correct parameters and examples.

**Step 10 — Commit and push**
```bash
git add .
git commit -m "Phase X: Brief description"
git push origin main
```

---

## 17. Common Mistakes to Avoid

**1 — Using the Neon pooler URL**
Always use the direct Neon URL (no `-pooler` in hostname). The pooler breaks migrations.

**2 — Hardcoding configuration values**
Never hardcode wallet addresses, prices, or any admin-configurable value in code. Use the `settings` table.

**3 — Putting logic in controllers**
If a controller method is longer than 30 lines, logic belongs in a Service.

**4 — Not using Enums for status fields**
Raw string comparisons like `=== 'approved'` scattered through code are bugs waiting to happen. Always use Enums.

**5 — Forgetting `$user->refresh()` after `create()`**
When you create a model and immediately return it, database defaults (like `credits_balance`) may not be loaded in memory. Call `->refresh()` after `create()` to reload from database.

**6 — Setting `APP_DEBUG=true` in production**
This exposes stack traces, file paths, and environment variables to attackers. Verify it is `false` on Railway.

**7 — Not running `php artisan config:clear` after `.env` changes**
Laravel caches config. Changes to `.env` are not reflected until the cache is cleared.

**8 — Forgetting `--force` flag on artisan commands in Docker**
`migrate --force` and `db:seed --force` are required in non-interactive (production) environments.

**9 — Skipping Scribe annotations**
Every endpoint must be documented. The FE developer uses the docs. Undocumented endpoints cause confusion and support requests.

**10 — Writing migrations that don't run on existing tables**
When adding columns to existing tables, always use `if (!Schema::hasColumn(...))` guards. The migration has already run in production — running it again without the guard causes a fatal error.

---

## 18. Security Rules

These are non-negotiable. Never bypass them.

**Authentication:**
- Every non-public route must be inside `middleware('auth:sanctum')`
- Admin routes must additionally have `middleware('admin')`
- Always check `if (! $request->user())` inside protected methods

**Data access:**
- Users can only access their own data. Always filter by `user_id`:
  ```php
  PaymentOrder::where('id', $orderId)->where('user_id', $user->id)->first();
  ```
- Never expose another user's data regardless of how the request is structured

**Input validation:**
- Every POST/PATCH/PUT endpoint must use a Form Request class
- Never trust raw `$request->all()` — always use `$request->validated()`

**File uploads:**
- Validate mime type AND file size on every upload
- Never save uploaded files to local disk (ephemeral on Railway)
- Always upload to Cloudinary immediately

**Rate limiting:**
- Public auth routes: `throttle:20,1`
- Protected routes: `throttle:120,1`
- Admin routes: `throttle:60,1`

**Secrets:**
- Never commit `.env` to git
- Never log tokens, passwords, or payment details
- Never return raw exception messages in production (handled by global exception handler)

**CORS:**
In production, `ALLOWED_ORIGINS` must list specific frontend domain(s) only. Never use `*` in production once the frontend domain is known.

---

## 19. External Services Reference

### Neon (PostgreSQL)
- Dashboard: `https://console.neon.tech`
- Always use the **direct connection URL**, never the pooler
- Free tier: 500MB storage, 10,000 compute hours/month

### Upstash (Redis)
- Dashboard: `https://console.upstash.com`
- Free tier: 10,000 commands/day
- Used for: queue driver, cache driver, rate limiting

### Cloudinary (File Storage)
- Dashboard: `https://cloudinary.com`
- Free tier: 25GB storage, 25GB bandwidth/month
- Files organized under `sales-spy/` folder:
  - `sales-spy/avatars/` — user profile pictures
  - `sales-spy/payment-proofs/` — payment screenshots
- Never store CDN URLs permanently without the `public_id` — you need the `public_id` to delete files later

### Railway (Hosting)
- Dashboard: `https://railway.app`
- Auto-deploys on push to `main`
- Environment variables set in Railway dashboard (not in code)
- Logs: Railway dashboard → Service → Deployments → View Logs

### TronScan (Payment Verification)
- URL: `https://tronscan.org`
- Used by admin to manually verify TRC20 USDT transactions
- Enter the TXID submitted by user to see transaction details
- Verify: recipient address matches wallet, amount matches order, status is confirmed

---

*Sales-Spy Backend — Internal Documentation v1.0*
*Last updated: May 2026*
*Maintained by the backend team*

---

## 20. Phase Progress Tracker

> This section tracks every phase of the project. Update the status column as each phase is completed. Any new developer joining the team should read the completed phases to understand what has been built and why decisions were made.

### Phase Status Legend
- ✅ **Complete** — Built, tested locally, deployed to Railway, all endpoints working
- 🔄 **In Progress** — Currently being built
- ⏳ **Pending** — Not started yet
- 🔒 **Blocked** — Waiting on another phase or external dependency

---

### Phase Overview Table

| Phase | Name | Status | Railway | Key Endpoints |
|---|---|---|---|---|
| 1 | Project Setup & Foundation | ✅ Complete | ✅ Live | `GET /api/v1/health` |
| 2 | Authentication System | ✅ Complete | ✅ Live | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, OAuth |
| 3 | User Profile & Settings | ✅ Complete | ✅ Live | `GET/PATCH /user/profile`, avatar, password, notifications, sessions |
| 4 | Plans & Subscription System | ✅ Complete | ✅ Live | `GET /plans`, `GET /user/subscription`, `POST /user/subscription/cancel` |
| 5 | Payments (Manual Crypto) | ✅ Complete | ✅ Live | `POST /payments/initiate`, proof upload, TXID submission |
| 6 | Credits System | 🔄 In Progress | ⏳ Pending | `GET /user/credits`, `GET /user/credits/history` |
| 7 | Transaction History | ⏳ Pending | ⏳ Pending | `GET /user/transactions` |
| 8 | Discovery Engine (Scrapers) | ⏳ Pending | ⏳ Pending | Python — no API endpoints |
| 9 | Websites Module | ⏳ Pending | ⏳ Pending | `GET /websites`, `GET /websites/{domain}` |
| 10 | E-commerce Intelligence | ⏳ Pending | ⏳ Pending | `GET /ecommerce`, `POST /ecommerce/{domain}/scan` |
| 11 | Export System | ⏳ Pending | ⏳ Pending | `POST /exports`, `GET /exports/{id}/download` |
| 12 | Dashboard Analytics | ⏳ Pending | ⏳ Pending | `GET /user/dashboard` |
| 13 | Notifications | ⏳ Pending | ⏳ Pending | `GET /user/notifications` |
| 14 | Admin Dashboard | ⏳ Pending | ⏳ Pending | `GET /admin/*` endpoints |
| 15 | Final Security & Production Hardening | ⏳ Pending | ⏳ Pending | — |

---

### Phase 1 — Project Setup & Foundation ✅

**What was built:**
- Fresh Laravel 13 project connected to PostgreSQL (Neon)
- All packages installed: Sanctum, Socialite, Spatie Permission, Spatie Query Builder, Spatie Data, Cloudinary PHP SDK, Predis, Laravel Horizon, Scribe
- Folder structure created: `Services/`, `Jobs/`, `Data/`, `Enums/`, `Exceptions/`, `Actions/`
- CORS configured via `.env` `ALLOWED_ORIGINS=*`
- `BaseController` with `successResponse()` and `errorResponse()` methods
- Health check route at `GET /api/v1/health`
- Docker setup with `Dockerfile`, `docker/nginx.conf` (dynamic `${PORT}`), `start.sh` written directly in Dockerfile to avoid CRLF issues
- Security headers middleware (`SecurityHeaders.php`)
- Rate limiting on all routes (20/min public, 120/min protected)
- Global JSON exception handler for all API routes

**Key decisions made:**
- PostgreSQL over MySQL — better JSONB support for storing store data
- Redis (Upstash free tier) for queues and cache
- Cloudinary for file storage — free tier, no credit card needed, ephemeral-safe
- `start.sh` written directly in Dockerfile — avoids Windows CRLF line ending bugs
- Neon direct connection URL (not pooler) — pooler breaks Laravel migrations

**Live URLs:**
- API: `https://sales-spy-api-production.up.railway.app`
- Docs: `https://sales-spy-api-production.up.railway.app/docs`

---

### Phase 2 — Authentication System ✅

**What was built:**
- `users` table with `credits_balance`, `credits_monthly_quota`, `profile_image_url`, `is_active`
- `oauth_providers` table for Google/GitHub links
- `User` model with `HasApiTokens`, `HasRoles`, `$guard_name = 'api'`
- `OAuthProvider` model
- `AuthService` — register, attemptLogin, findOrCreateOAuthUser, generateToken
- `RegisterRequest` and `LoginRequest` form requests with `bodyParameters()` for Scribe
- `AuthController` — register, login, logout, oauthRedirect, oauthCallback
- `RoleSeeder` — creates `admin` and `user` roles with `guard_name = api`
- Sanctum configured with `guard = ['web']` (NOT api — setting to api causes infinite recursion)
- Scribe docs fully annotated with Bearer token examples

**Key decisions made:**
- `sanctum.php` guard must be `['web']` not `['api']` — setting to api causes infinite loop in Sanctum's guard resolver
- Tokens deleted on every new login — one active token per user maximum
- `$user->refresh()` called after `User::create()` to load database defaults
- OAuth users get `email_verified_at = now()` automatically — Google/GitHub pre-verifies
- Free plan subscription assigned immediately on registration

**Endpoints:**
```
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/logout          (auth required)
GET    /api/v1/auth/{provider}/redirect
GET    /api/v1/auth/{provider}/callback
```

---

### Phase 3 — User Profile & Settings ✅

**What was built:**
- `notification_preferences` table — per-user notification toggles (7 booleans)
- `user_activities` table — activity log with IP, user agent, metadata
- `NotificationPreference` model with boolean casts
- `UserActivity` model with `$timestamps = false` (only has `created_at`)
- `ActivityService` — logs user actions, parses device from user agent
- `ProfileService` — updateProfile, updateAvatar, deleteAvatar, changePassword, getNotificationPreferences, updateNotificationPreferences
- `ProfileController` — 10 endpoints covering all settings tabs
- `UpdateProfileRequest` uses `Rule::unique()->ignore($this->user()?->id)` with nullsafe operator for Scribe compatibility

**Key decisions made:**
- Notification preferences in separate table not JSON column — faster to query individual booleans, easier to add new types
- `ActivityService` in its own class — called from AuthService (login), ProfileController (profile updates), PaymentService (payment events)
- Password change revokes all tokens except current — forces re-login on other devices
- `Rule::unique()->ignore()` must use `?->id` (nullsafe) — Scribe makes unauthenticated test requests which return null user

**Endpoints:**
```
GET    /api/v1/user/profile
PATCH  /api/v1/user/profile
POST   /api/v1/user/profile/avatar
DELETE /api/v1/user/profile/avatar
PUT    /api/v1/user/password
GET    /api/v1/user/notifications/preferences
PUT    /api/v1/user/notifications/preferences
GET    /api/v1/user/sessions
DELETE /api/v1/user/sessions/{sessionId}
DELETE /api/v1/user/sessions
```

---

### Phase 4 — Plans & Subscription System ✅

**What was built:**
- `plans` table — slug, name, monthly_price (cents), yearly_price (cents), monthly_quota, features (JSON), sort_order
- `subscriptions` table — user_id, plan_id, billing_cycle, status, current_period_start, current_period_end
- Removed `plan` column from `users` table — subscriptions table is now single source of truth
- `Plan` model with `getPriceInDollars()` and `isFree()` helpers
- `Subscription` model with `SubscriptionStatus` enum cast, `isActive()`, `isCancelledButActive()`
- `SubscriptionStatus` enum with `hasAccess()` method
- `BillingCycle` enum
- `SubscriptionService` — assignFreePlan, activateSubscription, cancelSubscription, expireSubscription, resetMonthlyCredits
- `PlanSeeder` — free ($0), basic ($115/mo), pro ($225/mo), enterprise (custom)
- `ExpireSubscriptions` command — runs daily at midnight
- `PlanController` — list plans (public), current subscription, cancel subscription

**Key decisions made:**
- Prices stored in cents (integer) — never floats for money
- `monthly_quota = -1` means unlimited (Enterprise)
- `updateOrCreate` in seeders — safe to run on every deployment
- Cancellation keeps access until `current_period_end` — not immediate cutoff
- Free plan subscription assigned on registration via `AuthService::register()`

**Endpoints:**
```
GET    /api/v1/plans                         (public — no auth)
GET    /api/v1/user/subscription             (auth required)
POST   /api/v1/user/subscription/cancel      (auth required)
```

---

### Phase 5 — Payments (Manual Crypto TRC20) ✅

**What was built:**
- `settings` table — key-value store for admin-configurable values (wallet address, credit costs, etc.)
- `payment_orders` table — reference, user_id, plan_id, billing_cycle, amount_usd_cents, status, txid, proof_image_url, expires_at
- `Setting` model with `get()` and `set()` static methods, Redis caching (1 hour TTL)
- `PaymentOrder` model with `PaymentStatus` enum cast, `canSubmitProof()`, `isExpired()`, `amount_in_dollars` accessor
- `PaymentStatus` enum with `isActionable()` and `isTerminal()` methods
- `PaymentService` — initiatePayment, uploadProof, submitTxid, approvePayment, rejectPayment
- `SettingSeeder` — wallet address, network, currency, expiry hours, site name, credit costs
- `ExpirePaymentOrders` command — runs hourly
- `PaymentController` — 5 endpoints for the complete payment flow
- Admin endpoints prepared: `approvePayment()` and `rejectPayment()` in PaymentService (used in Phase 14)
- `AdminUserController` — list users, show user, toggle active status
- `EnsureUserIsAdmin` middleware — protects all `/admin/*` routes

**Key decisions made:**
- Wallet address in `settings` table — admin can change without code deployment
- Order reference format `SPY-YYYY-NNNNN` — human-readable for support
- One pending order per user — new order expires all existing pending orders
- Screenshot required before TXID — prevents users submitting random TXIDs
- TXID validated as hexadecimal — catches typos immediately
- `DB::lockForUpdate()` in CreditService — prevents race conditions

**Endpoints:**
```
GET    /api/v1/payments
POST   /api/v1/payments/initiate
GET    /api/v1/payments/{orderId}
POST   /api/v1/payments/{orderId}/proof
POST   /api/v1/payments/{orderId}/txid

GET    /api/v1/admin/users
GET    /api/v1/admin/users/{userId}
PATCH  /api/v1/admin/users/{userId}/toggle-status
```

---

### Phase 6 — Credits System 🔄

**What is being built:**
- `credit_transactions` table — full audit log of every credit movement
- `CreditTransaction` model with `isDeduction()` and `absolute_amount` accessor
- `CreditService` — spend (with DB lock), add, refund, resetMonthlyCredits, canAfford, getCost, getHistory
- `CreditController` — balance endpoint (with cost table), history endpoint (paginated)
- `ResetMonthlyCredits` command — runs 1st of every month at midnight
- `SubscriptionService` updated to log credit additions through `CreditService`

**Endpoints being added:**
```
GET    /api/v1/user/credits
GET    /api/v1/user/credits/history
```

---

### Phase 7 — Transaction History ⏳

**What will be built:**
- A dedicated endpoint for payment transaction history (distinct from credit history)
- Filtering by status, payment method, date range
- Downloadable invoice PDF generation using `barryvdh/laravel-dompdf`
- Signed S3 URL (or Cloudinary URL) for invoice download, expires in 60 minutes

**Endpoints to be added:**
```
GET    /api/v1/user/transactions
GET    /api/v1/user/transactions/{id}
GET    /api/v1/user/transactions/{id}/invoice
```

---

### Phase 8 — Discovery Engine (Python Scrapers) ⏳

**What will be built:**
- Separate Python repository: `sales-spy-scrapers`
- `websites` table in PostgreSQL — any website type
- `ecommerce_stores` table — specifically e-commerce stores
- Celery workers for async discovery
- Platform detection logic for Wix, WordPress, Squarespace, Shopify, WooCommerce
- Data sources: Common Crawl, BuiltWith API, SerpAPI, domain feeds
- Contact extraction: email, phone, social links from website pages
- Direct PostgreSQL writes from Python (no API calls to Laravel)

**No new API endpoints in this phase.** Data is written directly to DB by scrapers. Laravel reads from the same tables.

---

### Phase 9 — Websites Module ⏳

**What will be built:**
- `WebsiteController` with search, filter, and single website detail endpoints
- Spatie Query Builder integration for clean URL-based filtering
- Credit deduction on every result returned (1 credit per result)
- Sensitive fields (email, phone) hidden from free plan users
- Full-text search via Meilisearch or Typesense (Laravel Scout)

**Endpoints to be added:**
```
GET    /api/v1/websites
       ?search=keyword&platform=wix&country=US&niche=photography
       &sort=newest|traffic&page=1&per_page=25

GET    /api/v1/websites/{domain}    (costs 1 credit)
```

---

### Phase 10 — E-commerce Intelligence Module ⏳

**What will be built:**
- `EcommerceController` with store search and deep scan
- Product catalog endpoint for Shopify stores
- Deep scan job — fetches all pages of `/products.json`, calculates real metrics
- Auto-update feature for watched stores (Pro and Enterprise only)
- `store_products` table for product catalog data

**Endpoints to be added:**
```
GET    /api/v1/ecommerce
       ?keyword=sneakers&platform=shopify&category=fashion
       &min_products=10&max_avg_price=200

GET    /api/v1/ecommerce/{domain}
GET    /api/v1/ecommerce/{domain}/products
POST   /api/v1/ecommerce/{domain}/scan    (costs 5 credits)
```

---

### Phase 11 — Export System ⏳

**What will be built:**
- `exports` table — tracks export jobs, status, file path, expiry
- `GenerateExportJob` — queued job that generates CSV/XLSX in background
- Upload completed file to Cloudinary
- Send email notification when ready
- FE polls export status until `ready`, then shows download button
- Exports expire and are deleted after 7 days
- 2 credits deducted per row at request time

**Endpoints to be added:**
```
POST   /api/v1/exports
GET    /api/v1/exports
GET    /api/v1/exports/{id}
GET    /api/v1/exports/{id}/download
```

---

### Phase 12 — Dashboard Analytics ⏳

**What will be built:**
- Single dashboard endpoint returning all stats in one call
- Stats: total leads accessed, credits remaining, searches this week, active exports
- Chart data: leads accessed per day (last 7 days and 30 days)
- Activity log: last 5 user activities from `user_activities` table
- All data cached in Redis for 5 minutes per user

**Endpoints to be added:**
```
GET    /api/v1/user/dashboard
```

---

### Phase 13 — Notifications ⏳

**What will be built:**
- Laravel's built-in notifications table
- In-app notifications: export ready, scan complete, low credits (below 10%)
- Email notifications: billing events, security alerts, new features
- Notification preferences respected (from Phase 3 `notification_preferences` table)
- Unread count shown in API responses

**Endpoints to be added:**
```
GET    /api/v1/user/notifications
PUT    /api/v1/user/notifications/{id}/read
PUT    /api/v1/user/notifications/read-all
DELETE /api/v1/user/notifications/{id}
```

---

### Phase 14 — Admin Dashboard ⏳

**What will be built:**
- Payment order review — admin approves or rejects crypto payments
- Full user management — view, search, adjust credits, change plan
- Settings management — update wallet address, credit costs, site settings
- Basic analytics — revenue, user growth, credit usage stats
- Crawl trigger — start/stop scraper jobs from admin panel

**Endpoints to be added:**
```
GET    /api/v1/admin/payments
PUT    /api/v1/admin/payments/{id}/review

GET    /api/v1/admin/users
GET    /api/v1/admin/users/{id}
PATCH  /api/v1/admin/users/{id}/credits
PATCH  /api/v1/admin/users/{id}/plan

GET    /api/v1/admin/settings
PUT    /api/v1/admin/settings

GET    /api/v1/admin/analytics
POST   /api/v1/admin/crawl/trigger
```

---

### Phase 15 — Final Security & Production Hardening ⏳

**What will be done:**
- Force HTTPS — `URL::forceScheme('https')` in AppServiceProvider
- Confirm `APP_DEBUG=false` on Railway
- Lock `ALLOWED_ORIGINS` to specific FE domain (no more wildcard `*`)
- Brute force login lockout after 10 failed attempts in 15 minutes
- Admin route additional IP restriction (optional)
- Full security audit of all endpoints
- Load testing with k6 or Locust
- Database query optimization — add missing indexes identified during load test
- Redis cache warming for frequently accessed data
- Final Scribe docs cleanup and review

---

## 21. Where to Put This Document in the Project

**Create a `docs/` folder in the project root:**

```
sales-spy-api/
├── app/
├── database/
├── docs/                          ← Create this folder
│   ├── TECHNICAL_DOCS.md          ← This file goes here
│   ├── ROADMAP.md                 ← Overall project roadmap
│   └── PHASE_1_SETUP.md           ← Phase 1 detailed setup guide
├── docker/
├── routes/
└── ...
```

**To add the docs folder and this file to your project:**

```bash
mkdir -p docs
# Copy this file into docs/TECHNICAL_DOCS.md
git add docs/
git commit -m "Docs: add internal technical documentation"
git push origin main
```

**Also add a brief pointer in your `README.md`** so any developer who clones the repo immediately knows where to look:

```markdown
# Sales-Spy API

Laravel 13 REST API for the Sales-Spy lead intelligence platform.

## Documentation
Full technical documentation: [docs/TECHNICAL_DOCS.md](docs/TECHNICAL_DOCS.md)
Live API docs: https://sales-spy-api-production.up.railway.app/docs

## Quick Start
See Section 4 (Local Development Setup) in TECHNICAL_DOCS.md
```

---

*Sales-Spy Backend — Internal Documentation v1.1*
*Last updated: May 2026*
*Current phase: Phase 6 — Credits System*
