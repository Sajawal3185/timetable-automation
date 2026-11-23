# Deploying the PHP backend to Render (staging)

The FastAPI service keeps running untouched. This creates a **second** Render
service and a **separate, empty** database, so the two backends can be
compared on day 8. Nothing here changes the live site: the frontend still
points at FastAPI until cutover.

## 1. A new empty database on Aiven

Use the same Aiven MySQL service as FastAPI, but a new database inside it:

1. Aiven console → your MySQL service → **Databases** → **Create database** → name it `timetable_php`.
2. On the service **Overview**, copy the **Service URI**. It ends in
   `/defaultdb?ssl-mode=REQUIRED`. Change `defaultdb` to `timetable_php`:

   ```
   mysql://avnadmin:PASSWORD@HOST:PORT/timetable_php?ssl-mode=REQUIRED
   ```

3. Download the **CA certificate** from the same page (`ca.pem`).

Never point PHP staging at `defaultdb`: Laravel's migrations would create
their tables next to FastAPI's. The container checks this on every start: if
the database has FastAPI's `alembic_version` table, it stops with "This is
FastAPI's database" before migrating anything.

## 2. The Render service

Render dashboard → **New** → **Web Service** → this repository, then:

| Setting | Value |
|---|---|
| Name | `timetable-php-staging` (the URL becomes `https://timetable-php-staging.onrender.com`) |
| Branch | `main` |
| Language | **Docker** |
| Root Directory | `backend-php` |
| Dockerfile Path | `./Dockerfile` |
| Instance type | Free |
| Health Check Path (Advanced) | `/api/health` |

There's no build or start command to type: the Dockerfile has both. On every
deploy, the container runs `php artisan migrate:deploy` (like `alembic
upgrade head`, see `routes/console.php`), then starts Apache. It holds a MySQL
lock while migrating, so if the service ever runs more than one instance, the
containers take turns instead of running the same migration twice.

## 3. Environment

In the service's **Environment** tab:

| Key | Value |
|---|---|
| `APP_KEY` | run `php artisan key:generate --show` on your laptop and paste the output (`base64:...`) |
| `DB_CONNECTION` | `mysql` |
| `DB_URL` | the URI from step 1, with `timetable_php` |
| `DB_SSL_CA` | `/etc/secrets/ca.pem` (see below) |
| `JWT_SECRET_KEY` | a new random value, **at least 32 characters**: `php -r "echo bin2hex(random_bytes(32));"`. Don't reuse FastAPI's |
| `CORS_ORIGINS` | the Netlify address, plus `http://localhost:5500,http://127.0.0.1:5500` for local testing |
| `CORS_ORIGIN_PATTERNS` | optional, for deploy previews: `#^https://deploy-preview-\d+--numl-archive\.netlify\.app$#`, no quotes here (several: separate them with spaces) |
| `EMAIL_PROVIDER` | `console` on staging (codes appear in the Render log) |

**Secret file:** Environment → **Secret Files** → add `ca.pem` with the
certificate's contents. Render puts it at `/etc/secrets/ca.pem`, and
`DB_SSL_CA` makes the connection verify Aiven's certificate.

`APP_ENV=production` and `APP_DEBUG=false` are already set in the image.
Leave them alone: with debug on, errors show file paths to anyone.

## 4. Check it

After the first deploy (a few minutes; the log ends with Apache starting):

- `https://<service>.onrender.com/api/health` → `{"status":"ok"}`
- `https://<service>.onrender.com/docs` → the API docs page
- `https://<service>.onrender.com/api/auth/me` → `401 {"detail":"Not authenticated"}`
- The deploy log shows the migrations running against `timetable_php` (or
  "Nothing to migrate" on later deploys).

**Check that the connection is encrypted and verified** (CI only tests
SQLite and a local MySQL, so this has to be done once on staging). With the
same three `$env:` lines as below, in PowerShell from `backend-php/`:

```
php artisan tinker --execute="dump(DB::select('SHOW SESSION STATUS LIKE ?', ['Ssl_cipher']), DB::scalar('SELECT DATABASE()'));"
```

`Ssl_cipher` must have a value (for example `TLS_AES_256_GCM_SHA384`), and the
database must be `timetable_php`. An empty cipher means TLS is off: check
`DB_SSL_CA`. A wrong or missing `ca.pem` fails right away with a certificate
or "DB_SSL_CA file not found" error instead of connecting unverified.

The database is empty until the seeder exists (day 3). To make a first admin
before that, run tinker **on your laptop against the staging database** (free
Render services have no Shell tab). In PowerShell, from `backend-php/`:

```
$env:DB_CONNECTION="mysql"; $env:DB_URL="<the timetable_php URI>"; $env:DB_SSL_CA="<path to ca.pem>"
php artisan tinker --execute="App\Models\User::create(['name'=>'Admin','identifier'=>'admin','role'=>'admin','password_hash'=>App\Services\Security::hashPassword('CHOOSE-A-PASSWORD')]);"
```

Close that terminal afterwards, so the next command doesn't hit staging by accident.

## Notes

- Render's free disk is wiped on every restart: uploads vanish until Supabase
  storage is set up (`AWS_*` variables, see `.env.example`).
- The free service sleeps after 15 minutes without requests; the first one
  after that takes about a minute.
- Logs: Render → **Logs**. Laravel writes to the log stream (`LOG_CHANNEL=stderr`).
- Every push to `main` that touches `backend-php/` redeploys (Render's auto-deploy).
