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
20. [Admin Dashboard](#20-admin-dashboard)
21. [Phase Progress Tracker](#21-phase-progress-tracker)
22. [Where to Put This Document in the Project](#22-where-to-put-this-document-in-the-project)

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
| `credit_transactions` | Immutable audit ledger for every credit movement |
| `websites` | Canonical website and contact records written by ingestion workers |
| `ecommerce_stores` | One-to-one commerce profile for an e-commerce website |
| `store_products` | Products belonging to an e-commerce store |
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

### Lead Ingestion Contract

The Python scraper repository and Laravel share the `websites`, `ecommerce_stores`, and `store_products` tables. Scrapers write these tables directly; Laravel treats them as the read model for the lead APIs.

**Canonical identity and ownership:**

- `websites.domain` is the primary upsert key. Store a lowercase host only, without scheme, path, port, or a leading `www.`.
- `websites.source + source_external_id` is an optional secondary source identity. `source_external_id` may be `NULL` when the source does not provide one.
- Each website has at most one `ecommerce_stores` row through unique `website_id`.
- A platform identity is unique by `ecommerce_stores.platform + platform_store_id` when the platform provides an ID.
- Products are upserted per store using `external_id` when available, otherwise `handle`. Production PostgreSQL requires at least one of them.
- Deleting a website cascades to its store and products. Scrapers should mark sites inactive/unreachable instead of deleting historical leads during normal crawl processing.

**Value conventions:**

- All timestamps are UTC. `created_at` and `discovered_at` are first-seen values and should not be replaced on subsequent upserts.
- `last_seen_at` means the source or crawler observed the record during the latest successful pass.
- Money is integer minor units: `1999` means USD 19.99 when `currency_code = USD`.
- Country and currency codes are uppercase. Country uses ISO 3166-1 alpha-2; currency uses ISO 4217.
- Evolving extracted structures belong in JSONB (`technologies`, social links, platform metadata, variants, and source payloads). Frequently filtered values belong in typed columns.
- `source_payload` is for provenance and debugging. Do not put secrets, access tokens, or unbounded page HTML in it.

**Fixed values shared with Laravel enums:**

```text
crawl_status: pending | crawling | completed | failed | blocked
website_status: active | inactive | parked | unreachable | unknown
commerce platform: shopify | woocommerce | wix | bigcommerce | magento |
                   prestashop | squarespace | custom | unknown
product status: active | draft | archived | unavailable
```

**Crawler claiming and retries:**

Workers select due records using `crawl_status IN (pending, failed, completed)` and `next_crawl_at <= NOW()` (or `NULL`). With multiple workers, claim rows in a short transaction using PostgreSQL `FOR UPDATE SKIP LOCKED`, then set `crawl_status = crawling` and increment `crawl_attempts`. Do network work outside that transaction. On completion set `completed`, `last_crawled_at`, `last_seen_at`, and a future `next_crawl_at`; on failure set `failed`, a bounded `last_crawl_error`, and retry time.

Example website upsert:

```sql
INSERT INTO websites (
    domain, canonical_url, name, source, source_external_id,
    crawl_status, discovered_at, last_seen_at, created_at, updated_at
) VALUES (
    'example.com', 'https://example.com', 'Example', 'common_crawl', 'record-123',
    'completed', NOW(), NOW(), NOW(), NOW()
)
ON CONFLICT (domain) DO UPDATE SET
    canonical_url = EXCLUDED.canonical_url,
    name = EXCLUDED.name,
    last_seen_at = EXCLUDED.last_seen_at,
    updated_at = EXCLUDED.updated_at;
```

Schema changes affecting these tables are API-contract changes for the scraper repository. Coordinate them before deployment and keep scraper writes backward compatible during rolling releases.

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

### Email Verification

`User` implements `MustVerifyEmail`. A verification email is sent on registration
(best effort — a mail failure does not fail the registration, since the account
and token are already valid).

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/auth/email/verify/{id}/{hash}` | Named `verification.verify`, `signed` middleware. Opened from a mail client, so it **redirects** to `FRONTEND_URL/email-verified?status=...` instead of returning JSON. Status is `verified`, `already-verified`, or `invalid`. |
| POST | `/api/v1/auth/email/resend` | Authenticated. Returns `sent: false` when already verified rather than erroring. |

Two things guard the link: the **signature** proves it came from us and has not
expired, and the **hash** of the address proves it was issued for the user's
*current* email — so a link stops working after an address change.

The link targets an API route rather than the SPA because signature validation
needs the app key. The API validates, then redirects.

Verification is **not currently enforced** on any endpoint. To require it, add
Laravel's `verified` middleware to a route group — but note that would lock out
every existing user registered before this feature, so backfill
`email_verified_at` first.

`AUTH_VERIFICATION_EXPIRE` (default 60 minutes) controls both the signature
expiry and the wording in the email, read from one place so they cannot disagree.

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

### Scraper Service Package

```
sales-spy-scrapers/
├── src/sales_spy_scrapers/
│   ├── application/             ← Crawl orchestration and state transitions
│   ├── domain/                  ← Immutable records, enums, normalization, errors
│   ├── infrastructure/          ← Safe HTTP and PostgreSQL adapters
│   ├── processors/              ← Contacts, platform detection, Shopify catalog
│   ├── sources/                 ← Common Crawl and future discovery adapters
│   ├── cli.py                   ← Health, enqueue, discovery, and batch commands
│   └── tasks.py                 ← Celery worker and beat configuration
├── tests/unit/                  ← Strict core suite with 85% coverage gate
├── tests/integration/           ← Isolated PostgreSQL adapter tests
├── requirements.lock           ← Fully pinned production dependency graph
├── pyproject.toml              ← Package, Ruff, MyPy, and pytest configuration
└── Dockerfile                  ← Non-root Celery runtime
```

The package is kept in this workspace while the service contract is evolving, but it builds and deploys independently from Laravel. It can be split into its own repository without changing package imports or database contracts.

### How Websites Are Detected

Detection is **HTML and header signature matching**, in `processors/platform_detector.py`.
It is a single ordered pass over the fetched homepage — it does not probe extra
endpoints. First match wins, so the order matters.

| Order | Platform | Signal | CMS | Flagged as a store? |
|---|---|---|---|---|
| 1 | Wix | `x-wix-meta-site-id` header, or `static.wixstatic.com` in HTML | `wix` | Only if `ecom.wix.com` or `wixstores` also appears |
| 2 | Shopify | `cdn.shopify.com`, `shopify.theme`, or `myshopify.com` in HTML | — | Always |
| 3 | WooCommerce | `woocommerce` or `wp-content/plugins/woocommerce` in HTML | `wordpress` | Always |
| 4 | WordPress | `wp-content`, `/wp-json/`, or `generator` meta | `wordpress` | No |
| 5 | Squarespace | `squarespace` in HTML or `generator` meta | `squarespace` | Only if `sqs-add-to-cart-button` or `product-item` appears |
| 6 | Webflow | `data-wf-site` attribute, or `webflow` in HTML | `webflow` | No |
| 7 | BigCommerce | `bigcommerce` in HTML | — | Always |

Anything else returns no platform, no CMS, and confidence `0.0`. The website row
is still stored — unrecognised platform is not a reason to discard a lead.

### IMPORTANT — detection coverage is not extraction coverage

These are two different capabilities and they do **not** line up:

- **Detected and flagged as a store:** Shopify, WooCommerce, BigCommerce, and
  conditionally Wix Stores and Squarespace Commerce.
- **Products actually extracted:** **Shopify only.** `application/crawler.py`
  gates catalog crawling on `store.platform == "shopify"`, and
  `processors/` contains only a Shopify catalog crawler.

So for a WooCommerce, Wix, Squarespace or BigCommerce store the API returns the
store with its platform, contacts and metadata, but `product_count` stays `0`,
`GET /ecommerce/{domain}/products` returns an empty page, and a deep scan has
nothing to fetch. Deep scan is Shopify-only for the same reason.

Two enum values are also currently unreachable: `CommercePlatform::MAGENTO` and
`PRESTASHOP` exist in both the PHP and Python enums, but no detector branch ever
returns them.

Adding a platform means adding a catalog crawler alongside `processors/shopify.py`
and extending the gate in `crawler.py` — the detector alone is not enough.

### Crawler Safety and Concurrency

- Every target and redirect hostname is resolved before the request; private, loopback, link-local, and reserved addresses are rejected.
- Robots policies are cached per origin and enforced before page/catalog requests.
- Global and per-host concurrency, per-host request rate, redirects, response bytes, retries, and Shopify pages are bounded by validated environment settings.
- Workers claim rows with `FOR UPDATE SKIP LOCKED`; each claim receives a UUID fence and expiry, and network work happens after the claim transaction commits.
- A configurable lease recovers `crawling` rows left by hard-killed workers. All completion/failure writes require the current claim token, preventing stale-worker overwrite.
- Lead timestamps use UTC `timestamp with time zone` at microsecond precision.
- Product snapshots mark missing products unavailable only when Shopify pagination confirms the catalog was complete.
- PostgreSQL writes use atomic upserts and preserve first-seen timestamps/provenance.

### Database Tables Populated by Scrapers

**`websites` table** — general websites of all types
**`ecommerce_stores` table** — specifically e-commerce stores

Both tables are read by the Laravel API but **only written to by the Python scrapers**. Laravel controllers only READ from these tables — they never write discovery data.

### Scraper to API Communication

The Python scrapers write directly to PostgreSQL. No API calls from scrapers to Laravel. This is intentional — direct DB writes are faster than HTTP and remove the Laravel API as a bottleneck during bulk ingestion.

Coordination is through the database, not a queue message. The API requests work
by writing a row — `POST /api/v1/ecommerce/{domain}/scan` inserts a
`store_scan_requests` row with status `queued`, and the Celery beat tick claims
it within a minute. There is no admin re-crawl endpoint yet; see Section 20 for
what the dashboard still needs.

---

## 15. Deployment — Railway

### Services on Railway

| Service | Type | Build source | Purpose |
|---|---|---|---|
| `sales-spy-api` | Web Service | root `Dockerfile` | Main Laravel API |
| `sales-spy-worker` | Background Worker | root `Dockerfile` | Queue processing (Horizon) |
| `sales-spy-cron` | Cron Job | root `Dockerfile` | Laravel scheduler (every minute) |
| `sales-spy-scraper-worker` | Background Worker | `sales-spy-scrapers/Dockerfile` | Celery worker — crawls websites, runs deep scans |
| `sales-spy-scraper-beat` | Background Worker | `sales-spy-scrapers/Dockerfile` | Celery beat — enqueues the crawl and scan ticks every 60s |

### IMPORTANT — the Python scraper does NOT deploy with the API

The root `Dockerfile` builds a **PHP-only** image. It runs `COPY . .`, so the
`sales-spy-scrapers/` directory ends up inside the image, but that image has no
Python interpreter and never starts Celery. Pushing to `main` therefore deploys
the API and nothing else — **the discovery engine will not run**.

The scraper needs **two additional Railway services**, both built from
`sales-spy-scrapers/Dockerfile` with the root directory set to
`sales-spy-scrapers`:

| Service | Start command |
|---|---|
| Worker | `worker --loglevel=INFO --queues=crawl --concurrency=1` (the image `CMD`) |
| Beat | `beat --loglevel=INFO` (override the `CMD`) |

Both share the same `ENTRYPOINT` (`celery -A sales_spy_scrapers.tasks:celery_app`).
Beat only schedules; without it, no crawl or scan ever starts. Without the
worker, tasks queue up and nothing is processed.

**Required variables on both scraper services** (see `sales-spy-scrapers/.env.example`):

| Variable | Notes |
|---|---|
| `SCRAPER_DATABASE_URL` | Same Neon database as the API. Use the **direct** URL, not the pooler. |
| `SCRAPER_REDIS_URL` | Celery broker. Upstash works; must be reachable from Railway. |
| `SCRAPER_SCHEMA_MIGRATION` | Must name the newest migration the worker's queries depend on. The startup guard refuses to run against an older schema. |
| `SCRAPER_USER_AGENT` | Identify the crawler with a domain **you control**, hosting a page that explains the bot. |

Deploy order matters: run the API first so migrations are applied, then start the
scraper services. The worker asserts schema compatibility on boot and exits if
the migration named in `SCRAPER_SCHEMA_MIGRATION` has not run.

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

## 20. Admin Dashboard

The admin surface is a set of API endpoints consumed by a separate dashboard
frontend. There are no server-rendered admin pages — this API only serves JSON.

### Access control

Every admin route sits behind four middleware, in this order:

```php
Route::middleware(['auth:sanctum', 'active', 'admin', 'throttle:60,1'])
    ->prefix('admin')
```

- `auth:sanctum` — valid bearer token
- `active` — rejects users with `is_active = false`
- `admin` — `EnsureUserIsAdmin`, requires the Spatie `admin` role
- `throttle:60,1` — 60 requests/minute per user

Roles are registered against the **`api` guard**, not `web`. `User::$guard_name`
is `'api'` and every seeded role row uses `guard_name => 'api'`. A role created
with the default `web` guard will silently fail the `admin` check.

Grant admin access with:

```php
$user->assignRole('admin');
```

### Current endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/admin/users` | List users — supports search, plan and status filters, pagination |
| GET | `/api/v1/admin/users/{userId}` | One user with subscription, credits and activity detail |
| PATCH | `/api/v1/admin/users/{userId}/toggle-status` | Activate or deactivate. Deactivating **revokes all of that user's tokens** |
| GET | `/api/v1/admin/payments` | List payment orders — filter by status |
| PUT | `/api/v1/admin/payments/{orderId}/review` | Approve or reject a payment awaiting verification |
| GET | `/api/v1/admin/metrics` | Dashboard summary — users, subscriptions by plan, revenue in cents, credits, lead counts |
| GET | `/api/v1/admin/metrics/pipeline` | Crawl and scan queue state, due count, stale claim counts |
| GET | `/api/v1/admin/activities` | Audit log across all users, filterable by `user_id` and `type` |
| POST | `/api/v1/admin/leads/enqueue` | Queue up to 500 domains for crawling |
| POST | `/api/v1/admin/leads/{domain}/recrawl` | Make one known domain due immediately |

Scribe groups these as `Admin — Users`, `Admin - Payments`, `Admin — Dashboard`,
and `Admin — Leads`.

### Reading the pipeline endpoint

`GET /admin/metrics/pipeline` is the fastest way to tell whether the Python
workers are alive, which matters because they deploy as separate Railway
services (Section 15):

| Symptom | Meaning |
|---|---|
| `due_now` climbing, `websites_by_crawl_status.completed` flat | Celery beat or the worker is not running |
| `stale_crawl_claims` persistently non-zero | Lease recovery is not running |
| `scans_by_status.queued` climbing | Scan processing is stalled |
| `last_completed_crawl_at` far in the past | Nothing has crawled recently |

### How domains enter the crawl queue

Three ways, all ending in the same place — a `websites` row with
`crawl_status = 'pending'` and a due `next_crawl_at`:

1. **`POST /admin/leads/enqueue`** — up to 500 domains you already know. Inserts
   rows only; performs no HTTP work. Known domains are reported as `skipped`, so
   the call is safe to retry.
2. **`discover-common-crawl <pattern>`** — the worker CLI. Streams the Common
   Crawl index for a URL pattern (`*.shop`) and can enqueue up to 100,000 domains
   in one run. This cannot be an API endpoint: it downloads a multi-megabyte
   index and would block a web request.
3. **`enqueue <domains...>`** — the worker CLI equivalent of option 1.

Beat then claims `SCRAPER_CLAIM_BATCH_SIZE` rows per minute (default 10, max 20),
so throughput is roughly **600–1,200 domains per hour**. Only one batch runs at a
time, guarded by a Redis lock.

**Important:** you cannot ask Common Crawl for "Shopify stores". It is indexed by
URL pattern, not platform. Platform is only known after the crawler fetches each
homepage and matches signatures. To end up with N Shopify stores you enqueue a
much larger set of candidate domains and let detection sort them — and only
Shopify yields products (Section 14).

### Manual re-crawl semantics

`POST /admin/leads/{domain}/recrawl` resets `crawl_attempts` to 0 as well as
setting the row due. That is required, not cosmetic: `crawl_attempts` is the
consecutive-failure ceiling the worker's claim predicate filters on, so a domain
parked as `blocked` would otherwise be set due and then skipped immediately.

A row with `crawl_status = 'crawling'` is left untouched and the response returns
`queued: false`. Resetting an in-flight row would strand the worker's claim token
and its completion write would be silently discarded by the fencing check.

### Rules specific to the admin surface

- **Deactivation is a security action, not a flag.** `AdminUserController`
  revokes every Sanctum token when a user is deactivated, so access is cut
  immediately rather than at token expiry.
- **Payment approval is what activates a subscription.** Only orders in
  `awaiting_verification` can be reviewed; approving one calls into
  `PaymentService` to grant the plan and credits. Never mutate a subscription
  directly from an admin controller.
- **The crypto wallet address is a setting, never a constant.** Read it via
  `Setting::get('crypto_wallet_address')` so an admin can rotate it without a
  deploy.
- **Admin actions must be attributable.** Log them through `ActivityService`
  against the *acting admin*, not the target user.

### Not yet built

The dashboard work still needs, in rough dependency order:

| Capability | Notes |
|---|---|
| Plan and settings management | `plans` and `settings` are seeded and read-only over the API today. |
| Credit adjustment | Granting or clawing back credits must go through `CreditService` with an idempotency key, never a direct `credits_balance` write. `CreditTransactionType::ADMIN_ADJUSTMENT` already exists for it. |
| Common Crawl discovery trigger | Would need the DB-coordination pattern used by deep scans: a `discovery_jobs` table the API writes and a beat task the worker polls. Today discovery is CLI-only. |
| Catalog crawlers beyond Shopify | The blocker on product coverage; see Section 14. |

---

## 21. Phase Progress Tracker

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

### Phase 8 — Discovery Engine (Python Scrapers) 🔄

**Completed foundation:**
- Shared `websites`, `ecommerce_stores`, and `store_products` ingestion schema
- Canonical identity, upsert, ownership, timestamp, JSONB, money, and crawler-state contract
- PostgreSQL constraints, GIN/full-text indexes, and due-crawl indexes
- Laravel models/enums and SQLite/PostgreSQL contract tests

**What will be built:**
- Separate Python repository: `sales-spy-scrapers`
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

## 22. Where to Put This Document in the Project

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
