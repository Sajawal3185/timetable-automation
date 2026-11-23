# Timetable Automation: PHP backend (Laravel)

The PHP version of `backend/` (FastAPI). Same API, same database; see
`docs/api-contract.md`. How to write code here: **[CONVENTIONS.md](CONVENTIONS.md)**.
The auth endpoints in detail: [AUTH-API.md](AUTH-API.md).
How it runs on Render: **[DEPLOY.md](DEPLOY.md)**.

## Run it on your laptop

Needs PHP 8.3+ and Composer (Laravel Herd installs both).

```
cd backend-php
composer install
cp .env.example .env
php artisan key:generate
```

In `.env`, set `JWT_SECRET_KEY` to a long random string:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Create the SQLite database (PowerShell: `New-Item database/database.sqlite`,
Mac/Linux: `touch database/database.sqlite`), then:

```
php artisan migrate
php artisan serve        # API at http://127.0.0.1:8000, docs at /docs
php artisan test
```

| Python | PHP |
|---|---|
| `pip install -r requirements.txt` | `composer install` |
| `uvicorn app.main:app --reload` | `php artisan serve` |
| `pytest` | `php artisan test` |
| `alembic upgrade head` | `php artisan migrate` |
| `python -m scripts.seed --reset` | `php artisan migrate:fresh --seed` |
