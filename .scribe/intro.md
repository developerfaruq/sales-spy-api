# Introduction

Welcome to the **Sales-Spy API** — e-commerce lead intelligence for serious sales teams.

Base URL: `https://sales-spy-api-production.up.railway.app`

---

## Response Format

Every endpoint returns the same JSON structure:

**Success:**

```json
{
  "success": true,
  "message": "Human readable message",
  "data": { ... }
}
```

**Error:**

```json
{
  "success": false,
  "message": "What went wrong",
  "errors": { ... }
}
```

**Paginated:**

```json
{
  "success": true,
  "message": "...",
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 25,
    "total": 243
  }
}
```

---

## HTTP Status Codes

| Code | Meaning                                    |
| ---- | ------------------------------------------ |
| 200  | Success                                    |
| 201  | Created successfully                       |
| 401  | Unauthenticated — token missing or invalid |
| 402  | Insufficient credits                       |
| 403  | Unauthorized — you don't have permission   |
| 422  | Validation failed — check the errors field |
| 500  | Server error                               |

---

## Credits System

Most data endpoints cost credits. Your balance is shown in every dashboard response.
Costs per action:

- View store details: **1 credit**
- Search results (per result): **1 credit**
- Export to CSV (per row): **2 credits**
- Deep scan a store: **5 credits**
