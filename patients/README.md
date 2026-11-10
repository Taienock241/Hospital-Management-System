# Hopewell Health Care

Hopewell Health Care contains separate patient and staff frontends with PHP endpoints and one PostgreSQL database. Patient accounts, staff records, appointments, departments, and queues are shared through the database; each portal has its own pages and role checks.

> **Important:** This is a development/demo project, not a production healthcare system. Use fictional data only. The separate staff app has administrative screens, but the combined project is not security-audited or compliance-certified. Do not enter real patient information.

## Requirements

- Windows with PHP 8.1+ and PostgreSQL
- A browser with JavaScript enabled
- PHP PDO PostgreSQL extension enabled (`pdo_pgsql`)

## Local development server

1. Start PostgreSQL.
2. Copy `staff\backend\.env.example` to `staff\backend\.env` and enter your database credentials.
3. Create a database named `hopewell_hospital` in PostgreSQL, connect to it, and run `database\hopewell_postgresql.sql`.
4. From the repository root, start the combined patient/staff server:

   ```powershell
   & 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t . router.php
   ```

5. Open [the patient portal](http://127.0.0.1:8000/patients/frontend/index.html) or [the staff sign-in](http://127.0.0.1:8000/staff/login.html). Use fictional patient data.

Keep the PHP server running. Do not open the HTML files with `file://`; both portals rely on same-origin API requests and PHP session cookies. The root `router.php` keeps backend configuration files out of direct web access.

## Configuration

`staff\backend\.env` supports:

```text
APP_ENV=development
HOPEWELL_DB_HOST=127.0.0.1
HOPEWELL_DB_PORT=5432
HOPEWELL_DB_NAME=hopewell_hospital
HOPEWELL_DB_USER=postgres
HOPEWELL_DB_PASSWORD=your-local-password
APP_TIMEZONE=Africa/Nairobi
```

Keep the actual `.env` file and database credentials out of version control. Use HTTPS in production.

## Backend layout

- `patients\backend\api\` — patient JSON endpoints, grouped by feature
- `patients\backend\controllers\` — request handling and application rules
- `patients\backend\config\` — session and shared PostgreSQL connection setup
- `patients\backend\utils\` — JSON responses, input checks and authorization helpers
- `database\hopewell_postgresql.sql` — shared PostgreSQL schema and fictional directory seed data
- `patients\assets\js\api.js` — shared Fetch API client and CSRF header handling
- `patients\docs\API_DOCUMENTATION.md` — endpoint methods, auth, payloads and responses

Patient authentication is stored in PHP sessions using an HTTP-only, SameSite=Lax cookie. Passwords are stored with `password_hash()`. State-changing requests use a session-bound CSRF token. Browser storage is not used for authentication or patient records.

## Functionality and known boundaries

- Registration and sign-in use database records; duplicate emails and invalid credentials are rejected.
- Google sign-in/sign-up and the old demo account chips are disabled; genuine OAuth has not been configured.
- The directory pages and appointment form load department and doctor information from PostgreSQL.
- Booking requires a signed-in patient and consent, validates the doctor schedule and future date, and reserves a unique time in a transaction. A queue entry and unpredictable appointment reference are created with the booking.
- Appointment history, cancellation and rescheduling are scoped to the signed-in patient.
- Queue lookup requires the booking patient to be signed in. Queue position is only calculated for the appointment day; wait estimates are intentionally not fabricated. The public department board contains aggregate current-day counts only.
- Staff queue actions update the same queue tickets that patient pages read, so patients see status changes for their own tickets.
- The contact inquiry form remains client-side only; inquiries are not saved to the database.
- Patient pages use their patient API endpoints under `/patients/backend/api`; staff pages use `/api`. Both APIs now connect to the same PostgreSQL database.

## Troubleshooting

- **API response is unavailable:** Confirm the PHP server is running and open the address above; check the PHP server terminal for errors.
- **Database service unavailable:** Confirm PostgreSQL is running and `staff\backend\.env` matches the local host, port, database, username and password.
- **Unknown database/table:** Connect to `hopewell_hospital` and run `database\hopewell_postgresql.sql`.
- **PDO driver missing:** Enable `pdo_pgsql` in the PHP configuration and restart PHP.
- **No appointment slots:** The selected doctor only accepts specific weekdays and hours. Choose a future day within that schedule.

## Legacy patient database files

`patients\database\schema.sql` and `patients\database\seed.sql` are the earlier MySQL version. Do not run them for the combined application; use `database\hopewell_postgresql.sql` instead.
