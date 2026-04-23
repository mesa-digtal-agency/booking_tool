# Beauty Booking Tool

A self-contained, Fresha-inspired online booking system for beauty businesses.
Plain PHP 7.4+, SQLite by default (MySQL optional), deployed via file upload +
a single `config.json` — no Composer, no npm, no build step.

## Features

- **Customer booking flow** — multi-step, mobile-first: service → professional → date & time → details → confirmation.
- **Self-service management** — every booking gets a unique emailed link (`/manage-booking.php?token=...`) where the customer can cancel or reschedule. Reschedule is disabled within 24 hours of the appointment.
- **REST API** — 6 JSON endpoints under `/api/` for services, staff, availability, bookings, and booking actions.
- **Admin panel** — dashboard with Chart.js analytics, weekly/daily calendar, bookings table with filters + CSV export, services/staff CRUD, working-hours editor, blocked-slot editor.
- **Roles** — `admin` sees everything; `staff` only sees their own calendar and bookings.
- **Emails** — PHP `mail()` or built-in single-file SMTP client (no PHPMailer / Composer needed).
- **Security** — prepared statements everywhere, CSRF tokens on all forms and POST API calls, session regeneration on login, secure cookies, password hashing via `password_hash()`.

---

## Deployment (3 steps)

### 1. Upload the project

Upload the entire folder to your web host's document root (e.g. `public_html/`).
Make sure your host runs PHP 7.4 or newer and has the `pdo_sqlite` (or `pdo_mysql`) extension.

The `/data` and `/assets/avatars` directories must be writable by the PHP process
(typically `chmod 775`).

### 2. Create your `config.json`

```bash
cp config.example.json config.json
```

Then edit `config.json`. At minimum set:

- `business_name`, `business_timezone`, `app_url`
- `from_email`, `from_name`
- If using MySQL: `db_type` = `"mysql"` plus `db_host`, `db_name`, `db_user`, `db_password`
- If using SMTP: `mail_driver` = `"smtp"` plus `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_encryption`

The SQLite default (`data/booking.sqlite`) requires no further setup — PHP will
create the file on first run.

### 3. Run the installer

Visit `https://yourdomain.com/setup.php`. Fill in your admin name, email, and
password. The installer will:

- Create all tables.
- Seed your account with default Mon–Fri 9am–5pm working hours.
- Refuse to run a second time once an admin exists.

**After setup, delete `setup.php` from the server.**

You can now log in at `/admin/login.php`.

---

## Configuration reference (`config.json`)

| Field | Purpose |
| --- | --- |
| `db_type` | `"sqlite"` (default) or `"mysql"` |
| `db_path` | SQLite file path (relative to project root) |
| `db_host`, `db_port`, `db_name`, `db_user`, `db_password` | MySQL credentials |
| `business_name` | Displayed in UI, emails, page titles |
| `business_timezone` | Any valid PHP timezone (e.g. `America/New_York`) |
| `business_logo_url` | Optional logo URL for header & emails |
| `app_url` | Public base URL — used to build absolute management links |
| `primary_color` | Brand accent color (hex) |
| `mail_driver` | `"mail"` (PHP `mail()`) or `"smtp"` |
| `smtp_host` / `smtp_port` / `smtp_username` / `smtp_password` / `smtp_encryption` | SMTP settings (`tls`, `ssl`, or empty) |
| `from_email`, `from_name` | Outgoing email identity |
| `slot_interval_minutes` | Booking slot grid step (15 or 30 recommended) |

---

## Directory layout

```
/                      index.php, manage-booking.php, setup.php, config.json, schema.sql, .htaccess
/api                   services.php, staff.php, availability.php, bookings.php, booking.php, booking-action.php
/admin                 login, dashboard, calendar, bookings, services, staff, working hours, blocked slots
/includes              bootstrap.php, db.php, auth.php, csrf.php, helpers.php, availability.php, mailer.php,
                       admin-layout.php, email-templates/
/assets                css/, js/, avatars/
/data                  SQLite database (writable)
```

---

## API quick reference

All endpoints return JSON. POST endpoints require `X-CSRF-Token` header (token is
exposed via the `<meta name="csrf-token">` tag on `/` and `/manage-booking.php`).

| Method & path | Body | Returns |
| --- | --- | --- |
| `GET /api/services.php` | — | `{services: [...]}` |
| `GET /api/staff.php?service_id=X` | — | `{staff: [...]}` |
| `GET /api/availability.php?staff_id=X|any&service_id=Y&date=YYYY-MM-DD` | — | `{slots: ["09:00", ...]}` |
| `POST /api/bookings.php` | `{service_id, staff_id, date, time, customer_name, customer_email, customer_phone, notes}` | `{booking, management_url}` |
| `GET /api/booking.php?token=UUID` | — | `{booking, can_reschedule}` |
| `POST /api/booking-action.php` | `{token, action:"cancel"|"reschedule", new_date?, new_time?}` | `{booking}` |

---

## Troubleshooting

- **"Missing config.json"** — copy `config.example.json` to `config.json`.
- **"Database unavailable"** — check `db_*` settings and that `/data` is writable (for SQLite).
- **Emails aren't sending with `mail()`** — most shared hosts restrict `mail()` senders. Switch to SMTP.
- **`setup.php` says setup already complete** — expected. Delete the file; use `/admin/login.php`.
- **Nginx users** — add the equivalent of the Apache rules in `.htaccess`:
  ```
  location ~ /(config\.json|config\.example\.json|schema\.sql|\.gitignore)$ { deny all; return 404; }
  location ~ \.(sqlite|sqlite-journal|db)$ { deny all; return 404; }
  location ^~ /data/ { deny all; return 404; }
  location ^~ /includes/ { deny all; return 404; }
  ```

---

## Customization tips

- **Branding** — `primary_color` in `config.json`, plus optional `business_logo_url`.
- **Email copy** — edit `includes/email-templates/*.php`.
- **Slot length** — `slot_interval_minutes` in `config.json`.
- **Service categories** — free-text on the service itself; services are grouped by category on the customer page.
- **Working hours** — `/admin/staff-hours.php`; per-day-of-week per staff member.
- **One-off time blocks** — `/admin/blocked-slots.php` or the calendar "+ Block time" button.
- **Moving to MySQL later** — change `db_type`, create the database, run `setup.php` once.

---

## License

Released for the commissioning business. Customize freely.
