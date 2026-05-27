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

All routes are under `/api/v1` unless noted.


| Method    | Path                                | Auth   | Description                                      |
| --------- | ----------------------------------- | ------ | ------------------------------------------------ |
| GET       | `/health`                           | —      | Health + MongoDB ping                            |
| POST      | `/api/v1/auth/sign-in`              | —      | Customer sign-in (phone + email)                 |
| POST      | `/api/v1/auth/vendor/sign-in`       | —      | Vendor sign-in                                   |
| POST      | `/api/v1/auth/register/planner`     | —      | Event planner registration                       |
| GET       | `/api/v1/auth/me`                   | Bearer | Current user                                     |
| GET       | `/api/v1/restaurants`               | —      | List restaurants (`?q=`, `?cuisine=`, `?isVeg=`) |
| GET       | `/api/v1/restaurants/{id}`          | —      | Restaurant detail + menu                         |
| GET       | `/api/v1/vendors`                   | —      | List vendors (`?category=`, `?city=`, `?q=`)     |
| GET       | `/api/v1/vendors/{id}`              | —      | Vendor detail                                    |
| POST      | `/api/v1/vendors/register`          | —      | New vendor listing (pending review)              |
| POST      | `/api/v1/vendors/{id}/enquiries`    | —      | Submit enquiry                                   |
| GET       | `/api/v1/vendors/{id}/enquiries`    | Bearer | Vendor enquiries                                 |
| GET       | `/api/v1/catalog/categories`        | —      | Food categories                                  |
| GET       | `/api/v1/catalog/coupons`           | —      | Festive coupons                                  |
| GET       | `/api/v1/catalog/vendor-categories` | —      | Wedding vendor categories                        |
| GET       | `/api/v1/campaigns`                 | —      | Marketing campaigns                              |
| GET       | `/api/v1/portfolio`                 | —      | Portfolio items                                  |
| GET/PATCH | `/api/v1/users/me`                  | Bearer | Profile                                          |
| POST      | `/api/v1/users/me/addresses`        | Bearer | Add address                                      |
| POST      | `/api/v1/orders`                    | Bearer | Place order                                      |
| GET/PUT   | `/api/v1/planner/workspace`         | Bearer | Event planner workspace sync                     |
| GET       | `/api/v1/admin/stats`               | Bearer | Admin dashboard metrics                          |
| GET       | `/api/v1/admin/customers`           | Bearer | Customer list                                    |
| GET/PATCH | `/api/v1/admin/orders`              | Bearer | Orders admin                                     |


### Authentication

Send JWT from sign-in responses:

```http
Authorization: Bearer <token>
```

Sign-in example:

```bash
curl -s -X POST http://localhost:8080/api/v1/auth/sign-in \
  -H 'Content-Type: application/json' \
  -d '{"phone":"9876543210","email":"you@example.com","customerType":"event-planner"}'
```

Planner workspace sync (replaces `localStorage` keys like `utsav_planner_events`):

```bash
curl -s -X PUT http://localhost:8080/api/v1/planner/workspace \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"events":[...],"guests":[...],"budgetLimit":500000}'
```

## Frontend integration

Point the React app at this API (e.g. Vite env):

```env
VITE_API_BASE_URL=http://localhost:8080/api/v1
```

Replace `MOCK_RESTAURANTS`, `ALL_MOCK_VENDORS`, and `localStorage` planner/auth flows with `fetch` calls to the endpoints above.

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
- Restrict `CORS_ORIGINS` to your real frontend domain(s).
- Run behind nginx/Apache with `public/` as document root.
- Use MongoDB TLS and application DB user with least privilege.

