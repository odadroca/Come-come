# Sprint 3 — Clinical Report Hardening + Cross-Feature Correlations

> **Status: READY TO BUILD.** First sprint in the re-sequenced roadmap (Plan of Record,
> `.claude/SPRINT-PLAN_reconciled.md`). **No schema change, no migration** — `schema_version`
> stays at 2. Highest value-per-effort: turns already-shipped data into a clinician-grade report.

## Goal

Convert the data ComeCome already collects (food log, weight, medication adherence, mood,
appetite, sleep) into (a) a coherent **"Clinical Summary"** at the top of the doctor-visit
report and (b) a guardian dashboard **"Insights"** panel — and build the
**sleep-quality → next-day appetite/mood correlation** that the Sprint 2 plan specced but never
actually computed.

## Child boundary

**ZERO child-facing changes.** No new pages, no footer change (stays 4/5), no check-in rows.
Purely a guardian/clinician read-layer over existing data. **No `sw.js` cache bump needed**
(no child asset changes).

## Effort

**M.** Single-file-heavy (`includes/helpers.php`) plus report/export wiring. No DB risk.

---

## Grounding (verified against current code)

- Portion → quantity map already exists: `little 0.25 / some 0.5 / lot 0.75 / all 1.0`
  (`includes/helpers.php:11–14`, and inline in queries).
- `getDashboardData($userId,$startDate,$endDate)` — `helpers.php:74`.
- `getReportData($userId,$startDate,$endDate)` — `helpers.php:159` (returns weights, medications,
  dailyMealCount, mealsByType, intakeByCategory, …).
- **⚠ Date-column gotcha:** `daily_checkin` keys on **`check_date`** (see `helpers.php:184`), while
  `food_log`/`weight_log` use `log_date`. Use the right column per table.
- **⚠ Sleep drift:** `sleep_log.sleep_start/sleep_end` were created `TEXT` by `migrateDatabase()`
  but declared `DATETIME` in `schema.sql`. Parse defensively with `strtotime()`.
- **⚠ Latent leak:** `getReportData()` sets `$user = getUserById($userId)`, which includes the
  **hashed `pin`**. `export.php` does `json_encode($reportData)` → the PIN hash may already be
  exported today. Task T6 fixes this.
- Export surfaces: `pages/guardian/export.php` (JSON), `export-html.php`, `export-csv.php`,
  `pages/guest-report.php`.

---

## Tasks

### T1 — `computeCorrelations($userId, $startDate, $endDate)` → `includes/helpers.php`
Descriptive, **rule-based (NOT AI)**, lag-1 correlation:
- For each day **D** with a `daily_checkin.sleep_quality`, pair it with day **D+1**'s
  `appetite_level`, `mood_level`, and that day's total food intake (reuse the portion map).
- **Gate on ≥ 5 paired days**; below that return `{ enough: false }` so the UI can say
  "not enough data yet."
- Return e.g. `{ enough, paired_days, sleep_vs_next_appetite: {direction:'positive|none|negative', note}, sleep_vs_next_mood:{…} }`.
- Use a simple, explainable signed measure (mean appetite on days after good sleep vs after poor
  sleep); **label outputs as descriptive association, never causal**.
- SQL must use `check_date` for `daily_checkin` and `log_date` for `food_log`.
- **Acceptance:** returns `enough:false` under 5 pairs; a stable narrative string otherwise.

### T2 — `deriveSleepStats($userId, $startDate, $endDate)` → `includes/helpers.php`
- Compute avg night-sleep duration (minutes) from `sleep_log` (`sleep_type='night'`) parsing
  `sleep_start/sleep_end` with `strtotime()` (handles the TEXT/DATETIME drift; skip rows that
  don't parse), and interruption frequency from `sleep_interruptions`. Nothing stored.
- **Acceptance:** returns sane averages on seeded data; tolerates missing/garbled times without error.

### T3 — Extend `getReportData()` with a `clinical_summary` section
Add a `clinical_summary` key containing:
- `med_adherence_pct` (from the existing medication taken/total aggregation),
- `appetite_trend` / `mood_trend` (avg + simple slope over the window, from `daily_checkin`),
- `sleep` (avg quality + T2 duration + interruption freq),
- `correlations` (T1 output).
- **Acceptance:** keys populated for a child with data; absent/zeroed gracefully when sparse.

### T4 — "Clinical Summary" block at the **top** of the report
- Render a takeaways-first narrative block in `pages/guardian/export-html.php` **and**
  `pages/guest-report.php` (clinician sees the summary before raw tables).
- Per **decision (iii)**: guest-report shows **age** (not raw DOB) — N/A this sprint (no DOB yet),
  but keep the helper that formats it ready.
- **Acceptance:** block appears in both HTML report and guest-report with the same figures.

### T5 — CSV columns → `pages/guardian/export-csv.php`
- Add flat columns: `avg_appetite, avg_mood, avg_sleep_quality, avg_sleep_duration_min,
  interruption_freq, med_adherence_pct, sleep_appetite_corr_note`.
- **Acceptance:** opens cleanly in a spreadsheet; columns match the HTML summary.

### T6 — JSON export whitelist → `pages/guardian/export.php`  *(establishes decision (iii) now)*
- Replace `json_encode($reportData)` with a **whitelisted projection**: include the clinical
  fields, **exclude `user.pin`** and any internal columns. This fixes the latent PIN-hash leak
  and sets the pattern so Sprints 5/8 (gender/DOB/percentiles) can't auto-leak later.
- **Acceptance:** exported JSON contains no `pin`/credential fields; includes `clinical_summary`;
  the four surfaces (HTML/CSV/JSON/guest-report) carry the same summary figures (parity check).

### T7 — Dashboard "Insights" panel → `pages/guardian/dashboard.php` (via `getDashboardData`)
- Surface the correlation narrative + a med-adherence headline; graceful "not enough data yet"
  state under 5 paired days.
- **Acceptance:** panel renders for the seeded guardian; sparse state shows the friendly message.

### T8 — i18n → `locales/pt.json` (canonical, real Portuguese) + `locales/en.json`
- Keys: `clinical_summary`, `insights_panel_title`, `med_adherence`, `avg_sleep_duration`,
  `interruption_frequency`, `correlation_sleep_appetite`, `correlation_none`, `not_enough_data`, …
- **Acceptance:** `pt.json`/`en.json` key parity holds; pt values are genuine Portuguese clinical
  phrasing, not English placeholders.

---

## Verification (end-to-end)

1. `cd Come-come && php -S localhost:8000`; log in as guardian (`Guardião` / `0000`).
2. Seed a few days of check-ins (appetite/mood/sleep) + food logs, or run against existing data.
3. Dashboard shows the **Insights** panel; with < 5 paired days it shows "not enough data yet".
4. Generate the HTML report, CSV, JSON, and a guest-report link — confirm the **Clinical Summary
   figures match across all four** and the JSON contains **no `pin`**.
5. Confirm **zero** child-facing change (child footer/pages untouched).
6. (If the Sprint 4 test harness exists) add an assertion for `computeCorrelations` gating at 5 pairs.

## Risks

- **Correlation is descriptive, not causal** — phrase all outputs as association; never imply the
  app diagnoses. Small sample sizes are common; the ≥5-pair gate + "not enough data" copy mitigate.
- **Sleep time drift** — defensive `strtotime()` parsing required (T2); don't assume DATETIME.
- **Parity drift** — treat HTML/CSV/JSON/guest-report as one checklist (T4–T6) so a field added to
  one isn't missed in another.
