# Sprint 11 — Growth-Support Nutrition Intelligence

> **Status: PLANNED — not started.** Last in the recommended sequence; it consumes the
> percentile, medication-timing, and sleep data produced by earlier sprints.
> Originally "Sprint 5" in the source plans (`docs/roadmap/SPRINT-PLAN.md`); appears as
> Sprint 10/11 in the re-sequenced roadmap.
>
> **Flagged for a design decision:** the maintainer wants to decide whether any part of
> this sprint should use **AI / LLM integration** before it is scheduled. That decision is
> the subject of §6 — the rest of the sprint (§1–§5) is specced as a deterministic,
> rule-based feature and does **not** require AI to ship.

---

## 1. Purpose

Turn the data ComeCome already collects (food log, weight/height percentiles, medication
schedule, sleep) into **behind-the-scenes nutrition intelligence for guardians and
clinicians** — specifically tuned to the reality of a neuro-divergent child on
appetite-suppressing stimulant medication, whose eating window is distorted by the drug's
onset/peak/rebound cycle.

The headline output is a clinician-facing **"Medication-Aware Nutrition Summary"**: *when*
in the medication day the child actually eats, *what* growth-supporting foods they get, and
how that correlates with growth trajectory and sleep — none of which the child ever sees.

## 2. Hard constraint — child boundary

**ABSOLUTELY ZERO child-facing changes.** The child logs food exactly as before (same
emoji foods, same portions, same celebration). Every tag and insight is invisible metadata
consumed only by guardians/clinicians. The child footer stays unchanged (≤ 5 items). This
is non-negotiable and is the whole reason the feature lives in the guardian/clinician layer.

## 3. Planned design (as specced — rule-based, **not** AI)

### 3.1 Data model

`food_growth_tags` — strategic, *non-micronutrient* tags chosen for ADHD/stimulant relevance
(not a full nutrition database):

| Tag | Clinical rationale |
|---|---|
| `calorie_dense` | Counteract appetite suppression — maximize intake in the small eating window |
| `protein_rich` | Growth + satiety; supports catch-up growth |
| `bone_building` | Calcium/vitamin-D foods; stimulant use is associated with growth concerns |
| `brain_fuel` | Foods supporting sustained energy/focus |
| `easy_to_eat` | Low-friction options for low-appetite periods |
| `hydrating` | Stimulants reduce thirst cues |

```
CREATE TABLE food_growth_tags (
  food_id INTEGER NOT NULL,
  tag     TEXT NOT NULL CHECK(tag IN
            ('calorie_dense','protein_rich','bone_building','brain_fuel','easy_to_eat','hydrating')),
  PRIMARY KEY (food_id, tag),
  FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
);
```
Seed the existing seed foods via `INSERT OR IGNORE`; mirror the table in `db/schema.sql`.
Guardian-added foods are tagged opt-in via checkboxes in `manage-foods.php` (non-blocking).

### 3.2 `includes/nutrition.php` — three deterministic analyzers (explicitly NOT AI)

- **A. Medication-timing analysis** — distribution of intake across `food_log.med_window`
  (`pre_med` / `onset` / `mid_med` / `post_med`, stamped at insert in Sprint 9), with flags
  like *"70% of intake is post-medication"* or *"nothing logged pre-medication 4 of 7 days."*
- **B. Growth-tag coverage** — rolling weekly servings per tag, trend arrows, and
  underserved-tag flags (e.g. *"protein-rich: 2 servings this week, down from 5"*).
- **C. Strategic recommendations** — rule-based suggestions cross-referencing tag frequency
  × `med_window` × percentile trajectory (Sprint 8) × sleep quality (Sprint 2). **This is the
  only part that "looks like AI" — see §6.** As specced it is a deterministic rules engine.

### 3.3 UI surfaces (guardian/clinician only)

- **Guardian dashboard** — a "Nutrition Intelligence" panel in `pages/guardian/dashboard.php`,
  guarded by a new `show_nutrition_insights` setting (**default `'0'` / OFF**), graceful on
  sparse data.
- **Clinician report** — a "Medication-Aware Nutrition Summary" section in
  `pages/guest-report.php` and `pages/guardian/export-html.php` (intake by window,
  tag-coverage trend, percentile trajectory, sleep-vs-next-day-appetite correlation).
- **CSV** — corresponding columns in `pages/guardian/export-csv.php`.

### 3.4 Migration & cross-cutting rules

- Add a version-gated block to `migrateDatabase()` (bump `schema_version` 5→6) creating
  `food_growth_tags`; mirror in `db/schema.sql`.
- **JSON-export privacy:** `export.php` auto-serializes `getReportData()` — add new fields to
  the whitelist deliberately so derived insights don't silently leak raw data.
- **i18n:** all tags, window names, panel sections, and recommendation phrasings into
  `locales/pt.json` (canonical, real Portuguese) + `locales/en.json`, key-parity verified.
- No `sw.js` cache bump needed (no child-facing asset changes).

## 4. Dependencies & sequencing

- **Sprint 9** — `medication_schedules` + `food_log.med_window` must exist and be populating
  (timing analysis has nothing to read otherwise; only forward-stamped rows have a window).
- **Sprint 8** — percentile data (for the growth-trajectory cross-reference).
- **Sprint 2** (shipped) — sleep quality (for the sleep↔appetite correlation).
- Comes **last** because it consumes all three at once and is the largest build.

## 5. Open / concept-only items — resolve in a discovery spike before building

1. **The actual recommendation rule set** — thresholds and copy for Part C are described in
   prose only; they need a concrete, testable specification.
2. **Growth-tag maintenance for the long tail** — guardian-added foods default untagged, so
   coverage silently erodes. Decide the nudge/coverage-indicator UX (not auto-tagging).
3. **SQLite analytics concurrency** — these aggregate queries run under per-call PDO
   write-lock contention; benchmark and decide read-only connections vs caching before the
   panel defaults on.

---

## 6. The AI / LLM question  ⟵ *the part to think about*

### 6.1 Why it feels AI-shaped but was specced rule-based

The "strategic recommendations" (§3.2-C) read like something an LLM would generate —
free-text, context-aware suggestions cross-referencing several data streams. But the source
plan deliberately specs it as a **deterministic rules engine** ("explicitly NOT AI"). That
choice fits the project's DNA: vanilla PHP, SQLite, **no external dependencies, no build
step, self-hosted by parents, "just upload and run."** The recommendations are
**medically adjacent** (a child's nutrition while on stimulant medication), so determinism =
auditability = safety.

### 6.2 Where an LLM could plausibly fit — three options

| Option | What the LLM does | Pros | Cons |
|---|---|---|---|
| **1. Rule-based (as planned)** | Nothing — deterministic rules + templated phrasing | Fully offline, auditable, zero cost, zero hallucination, **no data leaves the server**, no new dependency | Rules are rigid and labor-intensive to author; phrasing is templated/stiff; misses nuance |
| **2. LLM for narrative only ("last-mile prose")** | Rules compute the facts/flags; the LLM only **rephrases the given structured findings** into a fluent clinician paragraph + pt/en localization | Natural, readable clinician summary; LLM never decides clinical content; low hallucination (it summarizes supplied facts) | Sends *derived* metrics to an external API; needs a key + network; adds a dependency |
| **3. LLM as the recommendation engine** | Feed the child's tag/timing/percentile/sleep data; the LLM **produces the insights/recommendations** | Most flexible, least rule-authoring | **Highest risk:** hallucinated clinical claims next to a child's care; sends sensitive child health data out; non-deterministic/unauditable |

### 6.3 The decisive constraint — privacy & the self-hosted ethos

ComeCome stores **children's** names, food intake, weight, **medication**, mood, and sleep.
The app is self-hosted by families, has **no external dependencies today**, and its at-rest
DB encryption is *itself deferred*. Sending any of this to a third-party LLM API is a
**major posture change** and a likely regulatory issue (children's health data; under GDPR
this is special-category data, and the audience is Portuguese/EU families). **This — not
capability — is the deciding factor.** Option 3 in particular would route raw child medical
data to an external service; that is hard to reconcile with the product's promises.

It also has an infrastructure consequence: an LLM API key is **another secret**, so any LLM
route makes the deferred **`.env` / deployment-foundations** work (the same prerequisite
encryption needs) a hard dependency.

### 6.4 If you do go LLM — a privacy-preserving shape

If, after reflection, you want the LLM polish, the safe shape is:
- **Opt-in, default OFF.** Self-hosters who don't enable it never touch a network.
- **Option 2 (narrative-only), never Option 3.** The rules engine stays the source of truth;
  the LLM only rephrases computed findings under a constrained prompt ("rephrase these
  findings for a clinician; **do not add any clinical claim not present in the data**").
- **Send de-identified, aggregated metrics only** — no name, no DOB, no raw logs. E.g.
  *"weight-for-age ~P40, stable; 70% of intake post-medication; protein servings 4/week;
  sleep quality 3.2/5"* — not the child's records.
- **Configurable provider/key** (guardian settings or `.env`), defaulting to a current Claude
  model (Opus 4.8 / Sonnet 4.6 / Haiku 4.5 by cost/quality trade-off). Ideally **pluggable**
  so a privacy-maximizing self-hoster can point at a **local model** and keep everything on
  their own machine.
- Intersects the **at-rest encryption** and **TLS/transport** decisions — settle those first.

> When/if you choose the LLM route, exact model IDs, pricing, prompt-caching, and SDK details
> can be pulled from the `claude-api` reference before implementation.

### 6.5 Recommendation

**Ship Sprint 11 rule-based first (Option 1).** It is the specced design, fully offline,
auditable, and delivers the clinical value with **zero** change to the privacy posture.
Treat an LLM as an **optional, opt-in, narrative-only enhancement (Option 2)** layered on
later — gated behind the `.env`/secrets foundation and an explicit privacy decision, and
**never** Option 3. This keeps the child's medical-adjacent recommendations deterministic and
keeps the family's data on the family's own server by default.

## 7. Effort & risk

- **Effort: XL** — largest single sprint (data model + three analyzers + multi-surface UI +
  migration). Recommend splitting the discovery spike (§5) into its own gating sub-sprint.
- **Top risks:** unbounded rule-authoring scope (§5.1); SQLite read-lock contention under the
  analytics queries (§5.3); and — if AI is added — the privacy/regulatory exposure of §6.3.
