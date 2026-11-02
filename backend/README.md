# Hopewell PHP/PostgreSQL API

## Requirements

- PHP 8.1 or newer with `pdo_pgsql` enabled.
- PostgreSQL and the Hopewell schema.

On the current XAMPP PHP installation, `pdo_pgsql` and `pgsql` are present in `C:\xampp\php\ext` but commented out in `C:\xampp\php\php.ini`. Enable the `pdo_pgsql` and `pgsql` extension lines in that ini file, restart Apache/your PHP terminal, and confirm `pdo_pgsql` appears in `php -m` before connecting.

## Database setup

The API reads `backend/.env` automatically. Copy `backend/.env.example` to `backend/.env` if needed, then enter your local PostgreSQL settings there. `.env` is excluded from Git; never put real credentials in `.env.example`. Existing process environment variables override values from `.env`. If the database was created from `schema.sql` before the API was added, run `database/migrations/001_add_patient_active.sql` once to add reversible patient archiving.

Create the first system administrator using the commented bootstrap query at the end of `database/schema.sql`. Generate the password hash using PHP:

```powershell
php -r "echo password_hash('replace-with-a-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste only the resulting hash into the SQL bootstrap query. Do not commit the hash or real credentials.

## Run locally with XAMPP PHP

After filling in `backend/.env` and confirming PostgreSQL is running, run this from the repository root:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t frontend backend/public/router.php
```

Open `http://127.0.0.1:8000/staff/login.html`. Serving the frontend and API from this same PHP server keeps session cookies same-origin.

## API routes

- `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`
- Patient self-service: `GET /api/patient/me`, `GET /api/patient/appointments`, `GET /api/patient/queue`
- Patient requests: `POST /api/patient/appointments`, `POST /api/patient/queue`
- `GET/POST /api/patients`, `DELETE /api/patients/{id}` (archive)
- `POST /api/patients/{id}/portal-account` (trusted staff provisions a patient login)
- `GET/POST /api/departments`, `PATCH /api/departments/{id}`
- `GET /api/doctors`, `PATCH /api/doctors/{staffId}`
- `GET/POST /api/appointments`, `PATCH /api/appointments/{id}`
- `GET/POST /api/queue`, `PATCH /api/queue/{id}/status`
- `GET /api/dashboard`, `GET /api/reports`
- `GET/POST /api/staff`, `PATCH /api/staff/{staffId}`

Write requests after login require the `X-CSRF-Token` returned by `/api/auth/login` or `/api/auth/me`. Authorization is checked by the API, not by hiding frontend links.
