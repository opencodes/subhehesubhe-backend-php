# Shubhe Subhe Backend (PHP + MongoDB)

REST API for the **Utsav Connect** frontend (`utsav-connect-master`). Built with PHP 8.2+, [Slim 4](https://www.slimframework.com/), Composer, and MongoDB.

## Requirements

- PHP 8.2+ with extensions: `mongodb`, `json`
- [Composer](https://getcomposer.org/)
- MongoDB 6+ (already running on your machine is fine — no Docker required)

## Quick start

MongoDB should already be running. The API defaults to `mongodb://127.0.0.1:27017` and database `shubhesubhe` (see `.env.example`).

```bash
# 1. PHP + Composer (skip if already installed)
brew install php composer

# 2. MongoDB PHP extension (required — see "Fix: ext-mongodb missing" below)
brew tap shivammathur/extensions
brew install shivammathur/extensions/mongodb@8.5
php -m | grep mongodb   # should print "mongodb"

# 3. Install API dependencies
cd subhehesubhe-backend-php-master
composer install

# 4. Configure environment
cp .env.example .env
# Root login (auto-created on first start): ROOT_USERNAME=root, ROOT_PASSWORD=change-me-root-password
# Only change MONGODB_URI if yours is not localhost:27017, e.g.:
#   MONGODB_URI=mongodb://user:pass@127.0.0.1:27017/?authSource=admin

# 5. Seed data from utsav-connect mocks
composer seed

# 6. Run API
composer start
# → http://localhost:8080/health  (should show "mongodb": "connected")
```

**Optional:** `docker compose up -d` in this repo only if you want a separate MongoDB instance; skip it when you already have one running.

### Fix: `ext-mongodb` missing (Homebrew PHP 8.5)

Composer needs the **PHP MongoDB driver extension** (not the MongoDB server). Install it for your PHP version:

```bash
# Recommended (prebuilt, matches Homebrew PHP 8.5)
brew tap shivammathur/extensions
brew install shivammathur/extensions/mongodb@8.5

# Verify
php -m | grep mongodb
php --ini   # should list /opt/homebrew/etc/php/8.5/php.ini
```

**Alternative (PECL):**

```bash
brew install pkg-config openssl
yes '' | pecl install mongodb
echo "extension=mongodb.so" >> /opt/homebrew/etc/php/8.5/php.ini
```

Then retry:

```bash
composer install
```

Do **not** use `--ignore-platform-req=ext-mongodb` unless you only want to download packages; the API will not run without the extension.

### Existing MongoDB connection

| Your setup | `.env` value |
|------------|----------------|
| Default local, no auth | `MONGODB_URI=mongodb://127.0.0.1:27017` |
| Custom port | `MONGODB_URI=mongodb://127.0.0.1:27018` |
| Username + password | `MONGODB_URI=mongodb://USER:PASS@127.0.0.1:27017/?authSource=admin` |
| MongoDB Atlas | `MONGODB_URI=mongodb+srv://USER:PASS@cluster.mongodb.net/` |

Database name is controlled by `MONGODB_DATABASE` (default `shubhesubhe`). Seeding creates collections there; it does not touch your other databases unless you point `MONGODB_DATABASE` at them.

## API base URL

- **Base:** `http://localhost:8080`
- **Version prefix:** `/api/v1`
- **Health (no prefix):** `GET /health`

### Root user (local development)

On first API start, a **root** account is created in MongoDB (`platform_accounts`) from `.env` if it does not already exist.

| Variable | Default (`.env.example`) | Purpose |
|----------|--------------------------|---------|
| `ROOT_USERNAME` | `root` | Root login username |
| `ROOT_PASSWORD` | `change-me-root-password` | Root login password |

**Change these in production.** Root can create **admin** users (username + password) via `POST /api/v1/root/admins`. Admins sign in with `POST /api/v1/auth/platform/sign-in` and use the admin workspace in the frontend (`/platform/sign-in` → `/admin`).

### API reference

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| **Health** |
| GET | `/health` | — | Health + MongoDB ping |
| **Auth (public)** |
| POST | `/api/v1/auth/sign-in` | — | Customer sign-in (phone + email) |
| POST | `/api/v1/auth/vendor/sign-in` | — | Vendor sign-in (phone + email) |
| POST | `/api/v1/auth/register/planner` | — | Event planner registration |
| POST | `/api/v1/auth/platform/sign-in` | — | Root or admin sign-in (username + password) |
| **Auth (Bearer)** |
| GET | `/api/v1/auth/me` | Bearer | Current user or platform account |
| **Catalog (public)** |
| GET | `/api/v1/catalog/categories` | — | Food categories |
| GET | `/api/v1/catalog/coupons` | — | Festive coupons |
| GET | `/api/v1/catalog/vendor-categories` | — | Vendor categories (MongoDB; seeded on first read) |
| **Restaurants (public)** |
| GET | `/api/v1/restaurants` | — | List (`?q=`, `?cuisine=`, `?isVeg=`) |
| GET | `/api/v1/restaurants/{id}` | — | Detail + menu |
| **Vendors (public)** |
| GET | `/api/v1/vendors` | — | Approved vendors only (`?category=`, `?city=`, `?q=`) |
| GET | `/api/v1/vendors/{id}` | — | Vendor detail (approved only) |
| POST | `/api/v1/vendors/register` | — | Register listing → `status: pending_review` |
| POST | `/api/v1/vendors/{id}/enquiries` | — | Submit enquiry |
| **Vendors (Bearer)** |
| GET | `/api/v1/vendors/{id}/enquiries` | Bearer | List enquiries (vendor owner or admin/root) |
| GET | `/api/v1/vendors/{id}/dashboard` | Bearer | Vendor dashboard listing, including pending review |
| PATCH | `/api/v1/vendors/{id}` | Bearer | Update profile / address (vendor or admin/root) |
| POST | `/api/v1/vendors/{id}/services` | Bearer | Add service (vendor or admin/root) |
| POST | `/api/v1/vendors/{id}/change-password` | Bearer | Change vendor password |
| **Other (public)** |
| GET | `/api/v1/campaigns` | — | Marketing campaigns |
| GET | `/api/v1/portfolio` | — | Portfolio items |
| **Users (Bearer — customer JWT)** |
| GET | `/api/v1/users/me` | Bearer | Customer profile |
| PATCH | `/api/v1/users/me` | Bearer | Update profile |
| POST | `/api/v1/users/me/addresses` | Bearer | Add address |
| **Orders (Bearer)** |
| POST | `/api/v1/orders` | Bearer | Place order |
| **Planner (Bearer)** |
| GET | `/api/v1/planner/workspace` | Bearer | Get workspace |
| PUT | `/api/v1/planner/workspace` | Bearer | Save workspace |
| **Root (`role: root` only)** |
| GET | `/api/v1/root/admins` | Bearer root | List admin accounts |
| POST | `/api/v1/root/admins` | Bearer root | Create admin (`username`, `password`, `name`, `email?`) |
| PATCH | `/api/v1/root/admins/{id}` | Bearer root | Update admin (`name`, `email`, `password`, `active`) |
| **Admin (`role: admin` or `root`)** |
| GET | `/api/v1/admin/stats` | Bearer admin/root | Dashboard metrics (`pendingVendors`, etc.) |
| GET | `/api/v1/admin/customers` | Bearer admin/root | Customer list |
| GET | `/api/v1/admin/orders` | Bearer admin/root | Orders (`?status=`) |
| GET | `/api/v1/admin/orders/{id}` | Bearer admin/root | Order detail |
| PATCH | `/api/v1/admin/orders/{id}/status` | Bearer admin/root | Update order status |
| POST | `/api/v1/admin/restaurants` | Bearer admin/root | Create restaurant |
| PUT | `/api/v1/admin/restaurants/{id}` | Bearer admin/root | Update restaurant |
| DELETE | `/api/v1/admin/restaurants/{id}` | Bearer admin/root | Delete restaurant |
| POST | `/api/v1/admin/campaigns` | Bearer admin/root | Create campaign |
| PATCH | `/api/v1/admin/campaigns/{id}` | Bearer admin/root | Update campaign |
| GET | `/api/v1/admin/vendors` | Bearer admin/root | List all vendors (`?status=`, `?q=`) |
| POST | `/api/v1/admin/vendors/bulk` | Bearer admin/root | Bulk register vendors with nested `services` |
| PATCH | `/api/v1/admin/vendors/{id}/status` | Bearer admin/root | Approve/reject (`status`: `approved` \| `rejected` \| `pending_review`) |
| GET | `/api/v1/admin/vendor-categories` | Bearer admin/root | List vendor categories |
| POST | `/api/v1/admin/vendor-categories` | Bearer admin/root | Create category (`id`, `name`) |
| PUT | `/api/v1/admin/vendor-categories/{id}` | Bearer admin/root | Rename category (`name`) |
| DELETE | `/api/v1/admin/vendor-categories/{id}` | Bearer admin/root | Delete category |

### Authentication

Send the JWT from any sign-in response:

```http
Authorization: Bearer <token>
```

JWT claims include `role` (`customer`, `vendor`, `admin`, `root`) and `accountType: platform` for root/admin accounts.

#### Customer sign-in

```bash
curl -s -X POST http://localhost:8080/api/v1/auth/sign-in \
  -H 'Content-Type: application/json' \
  -d '{"phone":"9876543210","email":"you@example.com","customerType":"event-planner"}'
```

#### Root sign-in (default credentials from `.env`)

```bash
curl -s -X POST http://localhost:8080/api/v1/auth/platform/sign-in \
  -H 'Content-Type: application/json' \
  -d '{"username":"root","password":"change-me-root-password"}'
```

Save `token` from the response, then:

```bash
export TOKEN="<token>"

# Create an admin user
curl -s -X POST http://localhost:8080/api/v1/root/admins \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "username": "ops-admin",
    "password": "SecurePass123",
    "name": "Operations Admin",
    "email": "ops@example.com"
  }'
```

#### Admin sign-in (account created by root)

```bash
curl -s -X POST http://localhost:8080/api/v1/auth/platform/sign-in \
  -H 'Content-Type: application/json' \
  -d '{"username":"ops-admin","password":"SecurePass123"}'
```

Use the admin token for `/api/v1/admin/*` routes (vendor approval, categories, orders, etc.).

#### Vendor registration (public)

Required fields: `businessName`, `category`, `state`, `district`, `contactName`, `email`, `phone`, `description`. Optional: `primaryLocation`, `price`, `image`.

```bash
curl -s -X POST http://localhost:8080/api/v1/vendors/register \
  -H 'Content-Type: application/json' \
  -d '{
    "businessName": "New Venue Listing",
    "category": "venues",
    "state": "rajasthan",
    "district": "jaipur",
    "primaryLocation": "Jaipur, Rajasthan",
    "contactName": "Listing Owner",
    "email": "listing@example.com",
    "phone": "9988776655",
    "description": "Wedding venue in Jaipur.",
    "price": "On request",
    "image": "https://example.com/photo.jpg"
  }'
```

`state` and `district` are slugs (e.g. `rajasthan`, `jaipur`). Listing stays hidden until an admin sets `status` to `approved` via `PATCH /api/v1/admin/vendors/{listingId}/status`.

For `POST /api/v1/admin/vendors/bulk`, vendor profile `image` and nested service `image` are optional. If omitted, the API uses `DEFAULT_VENDOR_PROFILE_IMAGE` from `.env` and falls back to `https://images.unsplash.com/photo-1519225421980-715cb0215aed?w=500`.

#### Planner workspace

```bash
curl -s -X PUT http://localhost:8080/api/v1/planner/workspace \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"events":[...],"guests":[...],"budgetLimit":500000}'
```

## Frontend integration

Point the React app at this API (e.g. `utsav-connect-master/.env.local`):

```env
VITE_API_BASE_URL=http://localhost:8080/api/v1
```

| Frontend route | Purpose |
|----------------|---------|
| `/platform/sign-in` | Root / admin username + password |
| `/root` | Root console — manage admin users |
| `/admin` | Operations workspace (vendor approval, categories, commerce admin) |

Customer/vendor flows use phone + email at `/sign-in`. Platform operators do not use `ADMIN_EMAILS`; use root-created admin accounts instead.

## Project layout

```
public/index.php      # Front controller
src/
  Bootstrap/          # Slim app setup
  Config/             # Env + service context
  Controllers/        # HTTP handlers
  Middleware/         # CORS, auth, JSON
  Routes/             # Route map
  Services/           # MongoDB, auth/JWT
  Utils/
data/seed/            # JSON extracted from utsav-connect mocks
scripts/seed.php      # Load seed data into MongoDB
```

## Re-seed from frontend mocks

After updating `utsav-connect-master` mock data:

```bash
cd ../utsav-connect-master
node -e "
const fs=require('fs');const path=require('path');
const src=fs.readFileSync('src/data.ts','utf8');
const vendorsSrc=fs.readFileSync('src/components/web/VendorCategoryPage/mockData.ts','utf8');
const out=path.join('..','subhehesubhe-backend-php-master','data','seed');
const rest=src.match(/export const MOCK_RESTAURANTS[^=]*=\s*(\[[\s\S]*?\]);/)?.[1];
const vendors=vendorsSrc.match(/export const ALL_MOCK_VENDORS[^=]*=\s*(\[[\s\S]*?\]);/)?.[1];
const camps=src.match(/export const MOCK_MARKETING_CAMPAIGNS[^=]*=\s*(\[[\s\S]*?\]);/)?.[1];
fs.writeFileSync(path.join(out,'restaurants.json'), JSON.stringify(eval(rest),null,2));
fs.writeFileSync(path.join(out,'vendors.json'), JSON.stringify(eval(vendors),null,2));
fs.writeFileSync(path.join(out,'campaigns.json'), JSON.stringify(eval(camps),null,2));
"
cd ../subhehesubhe-backend-php-master && composer seed
```

## Production notes

- Set `APP_DEBUG=false` and a strong `JWT_SECRET`.
- Set a strong, unique `ROOT_PASSWORD`; rotate after first deploy.
- Restrict `CORS_ORIGINS` to your real frontend domain(s).
- Run behind nginx/Apache with `public/` as document root.
- Use MongoDB TLS and application DB user with least privilege.
- Store platform accounts only in `platform_accounts` (passwords are hashed with `password_hash()`).

## Customer Login
- email: rkjha.it.in@gmail.com
- password: "Customer@SubheHeSubhe"

## Vendor Login
- email: sushil@gmail.com
- password: "Vendor@SubheHeSubhe"
