# Lumera Lead Capture

A lightweight, production-ready multi-step lead capture funnel with a dynamic
funnel builder, for **https://go.lumeradubai.com**.

Plain PHP 8.1+, MySQL/MariaDB, vanilla JavaScript, Apache. No WordPress, no
framework, no frontend build step.

---

## Table of contents

1. [Project overview](#1-project-overview)
2. [Technical architecture](#2-technical-architecture)
3. [Server requirements](#3-server-requirements)
4. [Composer installation](#4-composer-installation)
5. [Environment configuration](#5-environment-configuration)
6. [Database creation](#6-database-creation)
7. [Schema installation](#7-schema-installation)
8. [Creating the first admin](#8-creating-the-first-admin)
9. [Running locally](#9-running-locally)
10. [Apache deployment](#10-apache-deployment)
11. [Subdomain DNS setup](#11-subdomain-dns-setup)
12. [VirtualHost configuration](#12-virtualhost-configuration)
13. [SSL setup](#13-ssl-setup)
14. [File permissions](#14-file-permissions)
15. [SMTP configuration](#15-smtp-configuration)
16. [Admin login](#16-admin-login)
17. [Editing funnel steps](#17-editing-funnel-steps)
18. [Adding options](#18-adding-options)
19. [Adding a new step](#19-adding-a-new-step)
20. [Publishing changes](#20-publishing-changes)
21. [Viewing leads](#21-viewing-leads)
22. [Exporting CSV](#22-exporting-csv)
23. [Updating branding](#23-updating-branding)
24. [Inspecting logs](#24-inspecting-logs)
25. [Security notes](#25-security-notes)
26. [Backup guidance](#26-backup-guidance)
27. [Troubleshooting](#27-troubleshooting)

---

## 1. Project overview

The system has two surfaces:

| Surface | URL | Purpose |
|---|---|---|
| Public funnel | `https://go.lumeradubai.com/` | A one-question-at-a-time lead capture funnel in English and Arabic |
| Admin dashboard | `https://go.lumeradubai.com/admin/` | Login-protected funnel builder, lead management and settings |

The funnel is **not hardcoded**. Every step, question, option, label,
translation, validation rule, colour and button caption is stored in the
database and edited through the dashboard. The public JavaScript contains no
assumptions about the number of steps, their order, their titles, the options
available, which fields are required, which languages exist, or progress
percentages — all of it is fetched at runtime from the published configuration.

The seeded funnel is **Lumera Property Finder** (`property-finder`) with six
steps: property purpose, property type, budget, preferred location, contact
information and privacy consent.

---

## 2. Technical architecture

```
lumera-lead-capture/
├── public/                     ← Apache DocumentRoot points HERE
│   ├── index.php                 public funnel shell
│   ├── .htaccess                 routing, security headers, caching
│   ├── favicon.ico  robots.txt
│   ├── admin/
│   │   ├── index.php             dashboard shell (auth required)
│   │   ├── login.php             sign-in page
│   │   └── preview.php           draft preview (auth required)
│   ├── api/
│   │   ├── public/               session.php · funnel.php · submit-lead.php
│   │   └── admin/                login · logout · dashboard · funnel · steps ·
│   │                             options · contact-fields · publish · leads ·
│   │                             lead-details · lead-status · lead-notes ·
│   │                             export · settings · upload
│   └── assets/
│       ├── css/                  public.css · admin.css
│       ├── js/                   public-funnel.js · admin.js · funnel-builder.js
│       └── uploads/              user uploads (script execution disabled)
├── src/                        ← never web-accessible
│   ├── Core/                     App, Config, Database, Router, Session, Auth,
│   │                             Csrf, Logger, Response, RateLimiter,
│   │                             AuditLog, SubmissionToken, AdminEndpoint
│   ├── Repositories/             Funnel, Step, Option, ContactField, Version,
│   │                             Lead, Settings, AdminUser
│   ├── Services/                 Funnel, Publish, Lead, Export, Upload, Dashboard
│   ├── Validators/               SubmissionValidator, StepValidator
│   ├── Mail/                     Mailer (PHPMailer/SMTP), LeadNotification
│   └── Support/                  Request, Str, Phone, StepType
├── database/                     schema.sql · seed.sql · migrations/
├── storage/                      logs/ · cache/ · rate-limit/
├── templates/                    public/ · admin/ · email/
├── bin/console.php               CLI: install, admin:create, funnel:publish…
├── vendor/                       Composer dependencies
├── .env                          secrets — never committed
├── .env.example
└── composer.json
```

**PSR-4 autoloading** maps `Lumera\` to `src/`. Composer packages: only
`phpmailer/phpmailer` and `vlucas/phpdotenv`.

### The draft / published model

This is the core design decision, and it is what keeps a visitor from ever
seeing a half-saved funnel:

* The administrator edits **draft** rows in `funnel_steps`,
  `funnel_step_options` and `funnel_contact_fields`.
* **Publish Changes** serialises the entire *active* draft into an immutable
  row in `funnel_versions` and flips `funnels.published_version` — inside a
  single transaction.
* The public funnel and the submission validator read **only** that published
  snapshot. A save, a reorder or a deletion in progress is invisible to
  visitors: they see the previous version in full until the new one goes live
  in one atomic step.

Structured tables remain the source of truth. JSON is used only for the
published snapshot and optional option metadata — never as a config blob.

### Server-authoritative submissions

The browser sends raw answer *values* and nothing else that matters. Step
types, option labels, scores, required flags and the funnel version are all
resolved server-side from the published snapshot, so a tampered payload cannot
invent an option, inflate a lead score, skip a required step or claim a
different funnel version.

### Historical answers

`lead_answers` stores a snapshot of the step key, step title, step type, answer
value, answer label and score at submission time. Deleting or renaming a step
later leaves old leads perfectly readable (`step_id` becomes `NULL`; the
snapshot stays).

---

## 3. Server requirements

| Component | Minimum | Notes |
|---|---|---|
| PHP | 8.1 | 8.2 or 8.3 recommended |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo`, `curl` | |
| MySQL | 8.0 | or MariaDB 10.5+ |
| Apache | 2.4 | with `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires` |
| Composer | 2.x | |
| TLS | required in production | Let's Encrypt is fine |

Enable the Apache modules if they are not already active:

```bash
sudo a2enmod rewrite headers deflate expires ssl
sudo systemctl restart apache2
```

---

## 4. Composer installation

```bash
cd /var/www/lumera-lead-capture
composer install --no-dev --optimize-autoloader
```

For local development (includes nothing extra — there are no dev dependencies):

```bash
composer install
```

---

## 5. Environment configuration

```bash
cp .env.example .env
php bin/console.php key:generate      # prints a fresh APP_SECRET
```

Paste the generated value into `.env`, then fill in the rest:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://go.lumeradubai.com
APP_TIMEZONE=Asia/Dubai
APP_SECRET=<from key:generate>
APP_SESSION_NAME=lumera_go_session
APP_SESSION_IDLE_MINUTES=60

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lumera_leads
DB_USERNAME=lumera_app
DB_PASSWORD=<strong password>
DB_CHARSET=utf8mb4

SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=notifications@lumeradubai.com
SMTP_PASSWORD=<smtp password>
SMTP_ENCRYPTION=tls
SMTP_AUTH=true

MAIL_FROM_ADDRESS=notifications@lumeradubai.com
MAIL_FROM_NAME="Lumera Dubai Real Estate"
LEAD_RECIPIENT_EMAIL=sales@lumeradubai.com
LEAD_REPLY_TO_MODE=lead_email

WHATSAPP_NUMBER=9715XXXXXXXX
WHATSAPP_DEFAULT_MESSAGE="Hello Lumera, I would like to know more about available properties."

RATE_LIMIT_MAX_ATTEMPTS=5
RATE_LIMIT_WINDOW_SECONDS=900
LOGIN_RATE_LIMIT_MAX_ATTEMPTS=5
LOGIN_RATE_LIMIT_WINDOW_SECONDS=900

STORE_RAW_IP=false
LOG_PATH=/var/www/lumera-lead-capture/storage/logs
```

Notes:

* `APP_SECRET` keys the HMAC used to pseudonymise IP addresses and build
  duplicate-submission fingerprints. Changing it invalidates existing hashes;
  stored leads are unaffected.
* `LEAD_RECIPIENT_EMAIL` accepts a comma-separated list.
* `STORE_RAW_IP=false` is the privacy-preserving default; only a keyed hash is
  stored.
* `.env` is in `.gitignore`. **Never commit it.**

---

## 6. Database creation

```sql
CREATE DATABASE lumera_leads CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'lumera_app'@'localhost' IDENTIFIED BY '<strong password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER, REFERENCES
  ON lumera_leads.* TO 'lumera_app'@'localhost';
FLUSH PRIVILEGES;
```

After installation you may drop `CREATE`, `ALTER` and `INDEX` if you prefer a
tighter runtime grant.

---

## 7. Schema installation

One command applies the schema, seeds the Lumera Property Finder funnel,
creates the first admin (if configured) and publishes version 1:

```bash
php bin/console.php install
```

Individual steps are also available:

```bash
php bin/console.php migrate    # database/schema.sql
php bin/console.php seed       # database/seed.sql
php bin/console.php funnel:publish
php bin/console.php funnel:status
```

Or apply the SQL directly:

```bash
mysql -u lumera_app -p lumera_leads < database/schema.sql
mysql -u lumera_app -p lumera_leads < database/seed.sql
php bin/console.php funnel:publish
```

Both SQL files are idempotent and safe to re-run.

### Tables

`admin_users`, `funnels`, `funnel_steps`, `funnel_step_options`,
`funnel_contact_fields`, `funnel_versions`, `leads`, `lead_answers`,
`lead_notes`, `app_settings`, `login_attempts`, `rate_limit_entries`,
`audit_logs`.

---

## 8. Creating the first admin

There is **no public registration**. Admins are created only from the CLI.

**Option A — interactive (recommended).** The password is prompted for, never
appears in your shell history, and is hashed with `password_hash()` before it
touches the database:

```bash
php bin/console.php admin:create
```

**Option B — from the environment.** Set these before running `install`, then
delete the password line afterwards:

```dotenv
ADMIN_INITIAL_EMAIL=admin@lumeradubai.com
ADMIN_INITIAL_PASSWORD=<at least 12 characters>
```

```bash
php bin/console.php install
```

Once the account exists it is never recreated or overwritten by a later
`install` run.

**Option C — documented SQL seed.** Generate a hash, then insert it. The
plaintext is never stored:

```bash
php -r 'echo password_hash("YourStrongPassword", PASSWORD_DEFAULT), PHP_EOL;'
```

```sql
INSERT INTO admin_users (email, password_hash, name)
VALUES ('admin@lumeradubai.com', '<paste the hash>', 'Administrator');
```

Other admin commands:

```bash
php bin/console.php admin:list
php bin/console.php admin:password admin@lumeradubai.com   # prompts
```

---

## 9. Running locally

```bash
composer install
cp .env.example .env      # point DB_* at your local MySQL, APP_URL=http://localhost:8080
php bin/console.php key:generate
php bin/console.php install
php -S localhost:8080 -t public
```

* Public funnel: <http://localhost:8080/>
* Admin: <http://localhost:8080/admin/>

Syntax checks:

```bash
php -l public/index.php
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;
```

> The PHP built-in server ignores `.htaccess`, so pretty URLs such as
> `/f/property-finder` only work under Apache. `/` and every `.php` endpoint
> work fine locally.

---

## 10. Apache deployment

```bash
sudo mkdir -p /var/www/lumera-lead-capture
sudo chown -R $USER:www-data /var/www/lumera-lead-capture

# upload / clone the project, then:
cd /var/www/lumera-lead-capture
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env
php bin/console.php install
```

**The DocumentRoot must be the `public/` directory.** Everything else —
`src/`, `storage/`, `database/`, `templates/`, `vendor/`, `.env` — then sits
above the web root and cannot be requested at all.

---

## 11. Subdomain DNS setup

Create an `A` record for the subdomain pointing at the server:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `go` | `<server IPv4>` | 3600 |
| AAAA (optional) | `go` | `<server IPv6>` | 3600 |

Verify before requesting a certificate:

```bash
dig +short go.lumeradubai.com
```

---

## 12. VirtualHost configuration

`/etc/apache2/sites-available/go.lumeradubai.com.conf`:

```apache
<VirtualHost *:80>
    ServerName go.lumeradubai.com
    DocumentRoot /var/www/lumera-lead-capture/public

    # Certbot serves its challenge from here; everything else goes to HTTPS.
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName go.lumeradubai.com
    DocumentRoot /var/www/lumera-lead-capture/public

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/go.lumeradubai.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/go.lumeradubai.com/privkey.pem

    <Directory /var/www/lumera-lead-capture/public>
        Options -Indexes -MultiViews +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Belt and braces: the project root is never a valid DocumentRoot.
    <Directory /var/www/lumera-lead-capture>
        <FilesMatch "^\.env">
            Require all denied
        </FilesMatch>
    </Directory>

    <DirectoryMatch "/var/www/lumera-lead-capture/(src|storage|database|templates|vendor|bin)">
        Require all denied
    </DirectoryMatch>

    # No PHP execution inside uploads, whatever ends up in there.
    <Directory /var/www/lumera-lead-capture/public/assets/uploads>
        php_admin_flag engine off
        Options -ExecCGI -Indexes
        <FilesMatch "\.(php[0-9]?|phtml|phar|pl|py|cgi)$">
            Require all denied
        </FilesMatch>
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/go.lumeradubai.com-error.log
    CustomLog ${APACHE_LOG_DIR}/go.lumeradubai.com-access.log combined
</VirtualHost>
```

`AllowOverride All` is required for `public/.htaccess` to take effect. If your
policy forbids it, copy the contents of `public/.htaccess` into the
`<Directory>` block instead.

```bash
sudo a2ensite go.lumeradubai.com
sudo apache2ctl configtest
sudo systemctl reload apache2
```

---

## 13. SSL setup

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d go.lumeradubai.com
sudo certbot renew --dry-run
```

Once the certificate is live, the HSTS header in `public/.htaccess` activates
automatically (it is scoped to HTTPS requests).

---

## 14. File permissions

```bash
cd /var/www/lumera-lead-capture

sudo chown -R www-data:www-data .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

# Writable by PHP
sudo chmod -R 775 storage public/assets/uploads
sudo chown -R www-data:www-data storage public/assets/uploads

# Secrets: readable only by the web user
sudo chmod 640 .env
sudo chown root:www-data .env
```

Verify from outside that private paths are unreachable — every one of these
must return 403 or 404:

```bash
for p in /.env /src/Core/Config.php /storage/logs/ /database/schema.sql \
         /vendor/autoload.php /composer.json /bin/console.php; do
  printf '%-30s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' https://go.lumeradubai.com$p)"
done
```

---

## 15. SMTP configuration

Notifications go out through PHPMailer over SMTP. PHP's `mail()` is never used.

```dotenv
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=notifications@lumeradubai.com
SMTP_PASSWORD=<password>
SMTP_ENCRYPTION=tls          # tls (STARTTLS, port 587) or ssl (SMTPS, port 465)
SMTP_AUTH=true
MAIL_FROM_ADDRESS=notifications@lumeradubai.com
MAIL_FROM_NAME="Lumera Dubai Real Estate"
LEAD_RECIPIENT_EMAIL=sales@lumeradubai.com
LEAD_REPLY_TO_MODE=lead_email
```

Each notification contains the contact details, every dynamic answer, the
attribution block, the lead ID, the funnel version, a direct WhatsApp link and
a plain-text alternative part.

**Delivery never gates the lead.** The lead and its answers are committed
first, in their own transaction. If SMTP then fails, the lead is kept, its
`email_status` is set to `failed`, the internal reason is written to the log
and shown on the lead detail page — and the visitor still sees the success
screen. Failures are visible on the dashboard as an "Email failures" card.

The `MAIL_FROM_ADDRESS` domain should publish SPF and DKIM records, or
notifications will land in spam.

---

## 16. Admin login

Go to `https://go.lumeradubai.com/admin/` and sign in with the account created
in step 8.

Protections in place: `password_hash()`/`password_verify()` with automatic
rehashing, session ID regeneration on login, HttpOnly + SameSite=Lax +
Secure-on-HTTPS cookies, a configurable inactivity timeout, rate limiting per
IP and per email, CSRF tokens on every state-changing request, a generic
"Invalid email or password." for every failure mode, and an entry in
`audit_logs` for both successes and failures.

---

## 17. Editing funnel steps

**Funnel Builder → select a step on the left.**

The editor gives you:

* **English content / Arabic content** tabs — title, description, placeholder
  and a custom validation message per language.
* **Step settings** — internal key, step type, required, active, auto-advance.
* **Validation** — minimum/maximum length, minimum/maximum value, regular
  expression pattern.
* **Conditional display** — optional: show this step only when another step's
  answer `equals` / `not_equals` / `contains` a value.
* **Options & scoring** for selection steps.

Press **Save Draft**. Nothing is public until you publish.

Reorder steps by dragging the `⠿` handle, or with the ▲ / ▼ buttons. Ordering
is written through an authenticated API inside a transaction.

Per-step actions: ⧉ duplicate (the copy is created **inactive** so it cannot go
live by accident), ✕ delete, and Activate / Deactivate.

Internal keys must be unique within a funnel and use only lowercase letters,
digits and underscores. They are language-independent identifiers used by the
API, the CSV export and stored leads — never use translated text as a key.

### Step types

| Internal value | Shown as |
|---|---|
| `single_select` | Single Select |
| `multi_select` | Multi Select |
| `short_text` | Short Text |
| `email` | Email |
| `phone` | Phone |
| `number` | Number |
| `dropdown` | Dropdown |
| `contact_information` | Contact Information |
| `consent` | Consent |
| `information` | Information Screen |

### Contact fields

The **Contact fields** panel controls what the Contact Information step shows:
`full_name`, `country_code`, `phone`, `email`, `preferred_language`,
`nationality`, `preferred_contact_method`.

You can edit labels, placeholders, validation and required state, and show or
hide optional fields. Contact fields are never deleted — `full_name`,
`country_code` and `phone` cannot even be hidden, because the funnel and the
lead record depend on them. This keeps existing leads readable.

---

## 18. Adding options

Select a selection step, then **Add option**:

| Field | Purpose |
|---|---|
| Internal value | Language-independent, unique within the step, e.g. `invest` |
| English label | e.g. `Invest` |
| Arabic label | e.g. `الاستثمار` |
| Icon identifier | Optional, e.g. an emoji |
| Score | Added to the lead score. **Never shown to visitors.** |
| Metadata | Optional JSON |
| Active | Inactive options are not published and cannot be submitted |

Options can be edited, duplicated, activated/deactivated, deleted and reordered
by drag-and-drop or ▲ / ▼.

Seeded scores: `invest` 20, `buy` 15, `rent` 5, `exploring` 0, `2m_5m` 20,
`above_5m` 30, `villa` 10, `1m_2m` 10.

---

## 19. Adding a new step

1. **Funnel Builder → Add Step.**
2. Give it an internal key (e.g. `timeline`), pick a type, write the English
   and Arabic titles.
3. Save Draft.
4. For a selection step, add at least one active option — publishing is blocked
   until you do.
5. Drag it into position.
6. **Preview**, then **Publish Changes**.

The public funnel picks the new step up with no code change: progress, the step
counter and navigation all recompute from the live step count.

---

## 20. Publishing changes

The Funnel Builder header shows the published version, the last published
time, whether the draft has unpublished changes, and the next version number.

**Preview draft** opens `/admin/preview.php` in a new tab. It renders the draft
through the exact same template and JavaScript as the live funnel, with a
Preview Mode badge, English/Arabic switching and RTL — and creates no lead.

**Publish Changes** writes a new immutable version and points the funnel at it
in one transaction. Publishing is refused, with the reason shown, if:

* the funnel has no active steps,
* no step collects an answer,
* a step is missing its English title,
* a selection step has no active options,
* a Contact Information step has no active contact fields.

Rollback: every version is retained in `funnel_versions`. To go back, set
`funnels.published_version` to the earlier number.

---

## 21. Viewing leads

**Leads** lists every submission with the name, phone, email, purpose, budget,
score, status, email delivery status and submission time.

Filters: free-text search (name, email, phone, lead ID), status, date range,
UTM source, campaign, and the internal values of the budget and purpose
answers.

Click a row for the detail view:

* **Overview** — contact details, consent with its timestamp, lead score,
  funnel version, device, screen size, user agent, hashed IP, email delivery
  status and any delivery error.
* **Answers** — every dynamic answer with the label as it was at submission
  time, plus the raw contact field values.
* **Attribution** — `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`,
  `utm_term`, `gclid`, `fbclid`, referrer, landing page.
* **Notes & timeline** — internal notes and the submission timeline.

Statuses: `new`, `contacted`, `qualified`, `unqualified`, `converted`,
`archived`. Archiving soft-deletes: the lead leaves the default list but stays
in the database and can be restored.

The raw IP is only ever shown when `STORE_RAW_IP=true`.

---

## 22. Exporting CSV

**Leads → Export CSV** downloads the currently filtered set.

The export includes the contact columns, one column per dynamic answer (derived
from the data, so a new step appears automatically), the full attribution
block, device metadata and email status. It is UTF-8 with a BOM so Excel reads
Arabic labels correctly, and cells beginning with `=`, `+`, `-` or `@` are
prefixed to defuse spreadsheet formula injection.

Exports are audit-logged with the row count and the filters used.

---

## 23. Updating branding

**Funnel Builder → Funnel settings** controls the public funnel: name, status,
default and enabled languages, primary/accent/background colours, submit button
captions, success title and message, WhatsApp button captions, privacy policy
URL, minimum completion time, logo, background image, and the progress bar,
step counter, back button, WhatsApp CTA and sessionStorage toggles.

**Settings** covers application-wide values: company display name, logo,
default interface language, timezone, privacy policy URL and the notification
subject template (tokens: `{lead_id}`, `{full_name}`, `{purpose}`, `{budget}`,
`{score}`, `{funnel}`).

Uploads accept PNG, JPG, JPEG and WEBP up to 2 MB. **SVG is rejected** — safe
SVG sanitisation is out of scope for this release, so it is not accepted
unsanitised. Uploads are validated by extension, by real MIME type and by
decoding as an image, are given a generated filename, and land in a directory
where script execution is disabled.

The colour and branding settings apply immediately; step and option changes
still require Publish.

---

## 24. Inspecting logs

Application logs are JSON lines in `storage/logs/app-YYYY-MM-DD.log`:

```bash
tail -f storage/logs/app-$(date +%F).log

# Failed notifications
grep lead.email_failed storage/logs/app-*.log | tail -20

# Rate limiting and abuse signals
grep -E 'rate_limited|honeypot|too_fast|csrf' storage/logs/app-*.log
```

Each line carries a request ID, event name, timestamp and a redacted context.
Passwords, the SMTP password, `APP_SECRET`, the database password, raw request
bodies and raw IPs are never written — any context key containing a
secret-shaped name is replaced with `[redacted]`, and IPs are stored as keyed
hashes unless `STORE_RAW_IP=true`.

Administrative actions are separately recorded in `audit_logs` (login success
and failure, logout, funnel updates, step and option create/update/delete/
reorder, publish, lead status changes, notes, archiving, CSV export, settings
updates and uploads) with the admin user, entity, metadata, hashed IP and
timestamp. The most recent entries appear on the dashboard.

Apache logs: `/var/log/apache2/go.lumeradubai.com-{error,access}.log`.

---

## 25. Security notes

**Data access** — every query uses PDO prepared statements with emulation
disabled. Admin input reaches SQL only through explicit column allow-lists.

**Sessions** — HttpOnly, SameSite=Lax, Secure over HTTPS, strict mode, ID
regenerated on login, bound to a user-agent fingerprint, with an inactivity
timeout.

**CSRF** — a per-session token with separate scopes for the public funnel, the
login form and the admin dashboard, compared in constant time. Required on
every state-changing admin endpoint and on public submission. The CSV export
requires it too, even though it is a GET, because it returns bulk data.

**Rate limiting** — public submissions are limited per IP; logins are limited
per IP *and* per email, with failures recorded in `login_attempts`. Bucket keys
are HMACs, so no raw IP or email is stored in the limiter.

**Bot defences** — a hidden honeypot field (a filled honeypot returns a normal
success response so the bot learns nothing), a server-enforced minimum
completion time, a 64 KB payload ceiling, and `application/json` content-type
enforcement.

**Duplicate submissions** — a single-use, session-bound, expiring submission
token issued when the session starts, verified on arrival and burned only once
the payload is valid and about to be stored (so a validation error does not
lock the visitor out of correcting it). A second layer fingerprints
funnel + phone/email and silently returns the existing lead if the same person
submits twice within five minutes.

**Input validation** — enforced on both sides, with the server authoritative.
Rejected: unknown step keys, unknown or disabled option values, disabled steps,
missing required answers, invalid email, invalid phone, missing consent,
oversized input, malformed arrays and unexpected nested structures. Client
labels, scores, step types and funnel versions are discarded and re-resolved
from the database.

**Output** — all template output is escaped through `Str::e()`. The admin UI is
built with DOM APIs and `textContent`, never string-concatenated HTML.

**Errors** — the public gets generic messages; details go to the log. Stack
traces and SQL errors are never exposed when `APP_DEBUG=false`.

**Uploads** — extension, MIME and image-decode validation, 2 MB cap, generated
filenames, and PHP execution disabled in the upload directory by both
`.htaccess` and the VirtualHost.

**Secrets** — only ever in `.env`. The settings API returns booleans such as
`smtp_configured`, never a credential, and the settings write path is a fixed
allow-list, so no request can introduce a key that shadows an environment
secret.

**Headers** — `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS on HTTPS, and
`no-store` on all PHP responses.

**Privacy** — IPs are stored as `HMAC-SHA256(ip, APP_SECRET)`. The raw IP is
stored only if you explicitly set `STORE_RAW_IP=true`, and is withheld from the
API and the CSV export otherwise.

---

## 26. Backup guidance

Database (the critical asset — it holds leads *and* the funnel configuration):

```bash
mysqldump --single-transaction --routines --triggers \
  -u lumera_app -p lumera_leads | gzip > lumera_leads_$(date +%F).sql.gz
```

Nightly cron:

```cron
0 2 * * * /usr/bin/mysqldump --single-transaction -u lumera_app -p'<pw>' lumera_leads \
  | gzip > /var/backups/lumera/leads_$(date +\%F).sql.gz
0 3 * * 0 find /var/backups/lumera -name '*.sql.gz' -mtime +30 -delete
```

Also back up `.env` (separately and encrypted — it holds every secret) and
`public/assets/uploads/`. `vendor/` does not need backing up; `composer install`
rebuilds it.

Restore:

```bash
gunzip < leads_2026-01-15.sql.gz | mysql -u lumera_app -p lumera_leads
php bin/console.php funnel:status
```

Housekeeping — prune expired rate-limit windows and old login attempts:

```cron
30 3 * * * cd /var/www/lumera-lead-capture && php bin/console.php prune
```

---

## 27. Troubleshooting

**"This form is not available yet." on the public funnel**
The funnel has never been published. Run `php bin/console.php funnel:publish`
or press Publish Changes in the dashboard. Check with `funnel:status`.

**"This form is currently unavailable." (HTTP 503)**
`funnels.status` is not `active`. Funnel Builder → Funnel settings → Status.

**The funnel shows the old questions after editing**
Draft changes are not public until published. The header will say "unpublished
changes". Press Publish Changes; use Preview draft to check first.

**Publish is refused**
The blockers are listed under the publish bar — usually a selection step with
no active options, or a step missing its English title.

**Login always says "Invalid email or password."**
Confirm the account exists with `php bin/console.php admin:list`, then reset it
with `php bin/console.php admin:password <email>`. The message is intentionally
identical for a wrong password and an unknown account.

**Locked out after repeated attempts (HTTP 429)**
Wait for `LOGIN_RATE_LIMIT_WINDOW_SECONDS`, or clear it:
`DELETE FROM rate_limit_entries;`

**Session drops immediately after signing in**
Usually `APP_URL` not matching the real host, or `Secure` cookies over plain
HTTP. Make sure the site is served over HTTPS in production and that
`APP_SESSION_NAME` is not shared with another app on the same domain.

**Leads are saved but no email arrives**
Look at the lead's email status. `skipped` means SMTP or
`LEAD_RECIPIENT_EMAIL` is not configured; `failed` means SMTP rejected the
message and the reason is on the lead detail page and in the log:

```bash
grep -E 'mail.send_failed|lead.email_failed' storage/logs/app-*.log | tail
```

Check SMTP host, port and encryption (587 → `tls`, 465 → `ssl`), and that
`MAIL_FROM_ADDRESS` is a real mailbox on an SPF/DKIM-signed domain.

**"Your session has expired. Please refresh the page." (HTTP 419)**
A CSRF token mismatch — normally a stale tab. Reload. If it is persistent,
check that the session cookie is being stored and that the server clock is
correct.

**"This form has already been submitted." (HTTP 409)**
The single-use submission token was already consumed. Reload the funnel to get
a fresh one.

**"Please take a moment to review your answers before submitting." (HTTP 422)**
The submission arrived faster than `min_completion_seconds`. Lower it in Funnel
Builder → Funnel settings if it is too aggressive for your funnel.

**Uploads fail**
Check `chmod 775 public/assets/uploads` and its ownership, and that PHP's
`upload_max_filesize` and `post_max_size` are at least 2 MB. SVG is rejected by
design.

**403 on every page**
`AllowOverride All` is missing from the `<Directory>` block, or `mod_rewrite`
is not enabled.

**500 with a blank page**
Check `/var/log/apache2/go.lumeradubai.com-error.log` and
`storage/logs/app-*.log`. Set `APP_DEBUG=true` temporarily — and turn it back
off immediately afterwards.

**Arabic renders as question marks**
Confirm the database, tables and connection are all `utf8mb4`
(`DB_CHARSET=utf8mb4`).

**Verify a deployment end to end**

```bash
php -l public/index.php
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;
php bin/console.php funnel:status
curl -s https://go.lumeradubai.com/api/public/funnel.php | head -c 400
curl -s -o /dev/null -w '%{http_code}\n' https://go.lumeradubai.com/.env    # must be 403
```
