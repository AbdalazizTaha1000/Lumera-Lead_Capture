# Migrations

The MVP ships its complete structure in two idempotent files, applied by the
console tool:

| File | Purpose | Command |
|------|---------|---------|
| `../schema.sql` | All tables, keys, indexes and constraints. Every statement is `CREATE TABLE IF NOT EXISTS`, so re-running it is safe. | `php bin/console.php migrate` |
| `../seed.sql`   | The Lumera Property Finder funnel, its steps, options, contact fields and default app settings. Uses `ON DUPLICATE KEY UPDATE`, so re-running it will not duplicate rows. | `php bin/console.php seed` |

`php bin/console.php install` runs both, creates the first admin from
`ADMIN_INITIAL_EMAIL` / `ADMIN_INITIAL_PASSWORD` (only if that account does not
already exist), and publishes funnel version 1.

## Adding a change later

Create a numbered file in this directory and apply it with the MySQL client:

```
database/migrations/2026_02_01_add_lead_source_column.sql
```

```bash
mysql -u <user> -p <database> < database/migrations/2026_02_01_add_lead_source_column.sql
```

Then mirror the change in `schema.sql` so a fresh installation stays in step.

Two rules keep the funnel data safe:

1. Never drop or rename `lead_answers.step_key`, `step_title`, `step_type`,
   `answer_value` or `answer_label` — these snapshots are what keep historical
   leads readable after the funnel is edited.
2. Never widen `app_settings` into a place for secrets. SMTP credentials, the
   database password and `APP_SECRET` belong in `.env` only.
