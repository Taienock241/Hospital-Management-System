# Hopewell Health Care API

All endpoints return JSON in this shape:

```json
{
  "success": true,
  "message": "Request completed.",
  "data": {}
}
```

Validation errors may include `errors`, keyed by request field. Endpoints are same-origin under `/patients/backend/api/`. They require a browser session created by PHP; do not send patient identifiers to select another account.

## CSRF and session

Call `GET /patients/backend/api/auth/csrf.php` before a state-changing request. It returns a `data.csrfToken`; send that value as `X-CSRF-Token`. The shared browser client in `patients\assets\js\api.js` handles this automatically.

Authentication uses the HTTP-only PHP session cookie. Sessions expire after 30 minutes without activity. `auth/me.php` reports whether a patient is authenticated.

## Authentication

| Method and path | Authentication | Request |
|---|---|---|
| `GET /patients/backend/api/auth/csrf.php` | No | None |
| `POST /patients/backend/api/auth/register.php` | No; CSRF required | `firstName`, `lastName`, `email`, `phone`, `dateOfBirth` (`YYYY-MM-DD`), `gender` (`female`, `male`, `nonbinary`, `other`, or empty), `password`, `termsConsent: true` |
| `POST /patients/backend/api/auth/login.php` | No; CSRF required | `email`, `password` |
| `POST /patients/backend/api/auth/logout.php` | Patient; CSRF required | Empty JSON object |
| `GET /patients/backend/api/auth/me.php` | Optional | None |

Example registration request:

```json
{
  "firstName": "Fictional",
  "lastName": "Patient",
  "email": "fictional.patient@example.test",
  "phone": "+1 555 010 0100",
  "dateOfBirth": "1990-04-12",
  "gender": "",
  "password": "replace-with-a-local-test-password",
  "termsConsent": true
}
```

Successful registration returns HTTP `201`; it does not sign the new user in. Login regenerates the session ID. Invalid credentials return `401`; duplicate registration returns `409`.

## Patient profile

| Method and path | Authentication | Request |
|---|---|---|
| `GET /patients/backend/api/patients/profile.php` | Patient | None |
| `PUT /patients/backend/api/patients/profile.php` | Patient; CSRF required | `firstName`, `lastName`, `phone` |

The patient identity comes from the session, never from a query or body-supplied patient ID.

## Directories

| Method and path | Authentication | Parameters |
|---|---|---|
| `GET /patients/backend/api/departments/index.php` | No | Optional `bookable=1` to return bookable departments only |
| `GET /patients/backend/api/doctors/index.php` | No | Optional `department=<department id>` and `search=<term>` |

Directory results include active data only. Doctor results are filtered to available doctors.

## Appointments

| Method and path | Authentication | Request |
|---|---|---|
| `GET /patients/backend/api/appointments/availability.php?doctor_id=...&date=YYYY-MM-DD` | No | Query parameters |
| `POST /patients/backend/api/appointments/create.php` | Patient; CSRF required | `departmentId`, `doctorId`, `date`, `time`, `visitType`, `reason`, `notes`, `termsConsent: true` |
| `GET /patients/backend/api/appointments/index.php` | Patient | None; returns only the current patient's appointments |
| `GET /patients/backend/api/appointments/show.php?reference=...` | Patient | Appointment reference |
| `POST /patients/backend/api/appointments/cancel.php` | Patient; CSRF required | `reference` |
| `POST /patients/backend/api/appointments/reschedule.php` | Patient; CSRF required | `reference`, `date`, `time` |

Visit types are `in-person`, `followup`, and `diagnostic`. Appointment dates must be future dates matching the selected doctor's work days, and requested times must be available within the doctor's hours. The server verifies that the doctor belongs to the selected department. A database unique key and transaction protect active time slots from double booking. References are generated on the server.

Appointments and queue records are always constrained to the authenticated patient. Unknown or other-patient references return `404`.

## Queue

| Method and path | Authentication | Request |
|---|---|---|
| `GET /patients/backend/api/queue/status.php` | Patient | None; returns the patient's active queue entry for today, if any |
| `POST /patients/backend/api/queue/lookup.php` | Patient | JSON `{ "reference": "APT-..." }` |
| `GET /patients/backend/api/queue/board.php` | No | None; aggregate department counts for today only |

Queue lookup is intentionally authenticated to prevent reference enumeration and disclosure of other patients' data. For future appointments, the response does not claim an active position or wait estimate. The current schema supports `waiting`, `in_progress`, `completed`, and `cancelled`; queue operations for staff are not implemented.

## Common HTTP status codes

- `200` request succeeded
- `201` account or appointment created
- `401` patient sign-in required or invalid credentials
- `403` invalid or expired CSRF token
- `404` resource unavailable to the current account
- `405` unsupported HTTP method
- `409` duplicate email or appointment slot conflict
- `422` invalid input
- `500` unexpected server failure (details are logged server-side, not returned)

## Manual local test checklist

1. Run `database\hopewell_postgresql.sql` in the `hopewell_hospital` database, then register a fictional patient.
2. Try registering the same email twice; expect `409`.
3. Sign in with the new account, then try an incorrect password; expect `401`.
4. Visit the dashboard signed out; it should redirect to sign-in. Sign in, then verify only that account's profile and appointments display.
5. On the booking page, choose a seeded doctor, a future working day and an available time. Submit without consent and expect validation failure; accept consent and book.
6. Book the same doctor's date/time again from the same or another test account; expect a conflict.
7. Check the created reference in the dashboard and queue lookup. Try a made-up or another account's reference; no other patient's details should be returned.
8. Cancel or reschedule from the dashboard and confirm the appointment/queue state changes.
9. Sign out and confirm the dashboard and queue lookup require sign-in.
10. Run PHP syntax checks on the PHP files using the XAMPP PHP executable, for example `C:\xampp\php\php.exe -l patients\backend\controllers\AuthController.php` from the repository root.
