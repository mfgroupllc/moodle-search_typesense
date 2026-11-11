# Search Analytics, Zero-Result Insights & Privacy (`local_typesensepro`)

This guide covers the **Search Analytics & Zero-Result Insights Dashboard** (`/local/typesensepro/analytics.php`), CSV export hardening, automated retention pruning, and GDPR Privacy API implementation in `local_typesensepro`.

---

## 1. Search Analytics Dashboard (`/local/typesensepro/analytics.php`)

Accessible via **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Search Analytics**, the dashboard provides actionable search telemetry over configurable windows (**Last 7 days**, **Last 30 days**, **Last 90 days**):

### 1.1 Key Performance Indicators (KPI Cards)
- **Total Searches:** Count of Page 1 user queries executed within the selected window.
- **Average & p95 Search Latency (`ms`):** End-to-end server execution time (including Typesense candidate retrieval and Moodle permission verification).
- **Zero-Result Rate (`%`):** Percentage of searches returning 0 authorized results.
- **Historical Spillover Queries:** Number of searches executed in `Current courses` that matched historical courses or past cohort codes.

### 1.2 Actionable Insight Tables
1. **Top Zero-Result Queries in Current Courses:** Highlights terms learners or faculty are searching for that have no matching content in the active term (helping instructional designers spot missing syllabus links, unindexed activities, or terminology gaps).
2. **Top Historical Spillover Queries:** Shows queries where users searched `Current courses` for content located in `Historical / other courses`.
3. **Top Searches Overall:** Displays query volume, average result count, and average latency (`ms`) for the most frequent search terms.

### 1.3 Formula-Safe CSV Export (`?download=csv`)
Clicking **Export CSV** downloads the aggregated query report with:
- Localized column headers via `get_string('analytics_csv_col_*', 'local_typesensepro')`.
- **CSV Formula Injection Protection:** Any query string beginning with `=`, `+`, `-`, `@`, `\t`, or `\r` is automatically prefixed with a single quote (`'`) so opening the CSV in Microsoft Excel or Google Sheets can never execute spreadsheet formulas.

---

## 2. Automated Log Retention & Cleanup (`\local_typesensepro\task\cleanup_analytics_task`)

- Controlled by `local_typesensepro/analyticsretentiondays` (default: **`90` days**).
- The daily scheduled task `\local_typesensepro\task\cleanup_analytics_task` deletes rows from `{local_typesensepro_log}` where `timecreated < now - (analyticsretentiondays * 86400)`.

---

## 3. GDPR & Moodle Privacy API (`\local_typesensepro\privacy\provider`)

`local_typesensepro` implements:
- `\core_privacy\local\metadata\provider`
- `\core_privacy\local\request\core_userlist_provider`
- `\core_privacy\local\request\plugin\provider`

### 3.1 Stored Database Metadata (`{local_typesensepro_log}`)
Declares and exports all user-linked fields in `{local_typesensepro_log}`:
- `userid` — ID of the user who executed the search
- `querytext` — Normalized search query string (up to 255 characters)
- `queryhash` — SHA-1 hash of the lowercase normalized query
- `scope` — Search scope (`current`, `historical`, `all`, `videotime`)
- `resultcount` — Number of authorized results returned on Page 1
- `limited` — Whether candidate scanning stopped at the cooperative time/candidate budget
- `historicalhits` — Number of matching historical courses detected when searching `Current courses`
- `elapsedms` — Search execution duration in milliseconds
- `timecreated` — Unix timestamp when the search occurred

### 3.2 External Subsystem & AI Provider Disclosures
- Discloses data sent through Moodle's `core_ai` subsystem when AI Overviews (`enableragsummary`) or AI Category Classification (`enableaiclassifier`) are used.
- Discloses document and query text sent to the configured `embeddingendpoint` when both `enablehybridsearch` and explicit administrator consent (`embeddingconsent`) are enabled.
