# Typesense Global Search Engine for Moodle (`search_typesense`)

A high-performance Moodle **global search** engine plugin (`\search_typesense\engine` extending `\core_search\engine`) that indexes your Moodle site into [Typesense](https://typesense.org) and serves sub-second, typo-tolerant, accent-insensitive search at multi-year institutional scale.

- **Component:** `search_typesense`
- **Current Release:** `v1.1.9` (`2026111006`)
- **License:** GNU GPL v3 or later ([`LICENSE`](./LICENSE))
- **Supported Moodle Versions:** Moodle 4.1 LTS, 4.5 LTS, and Moodle 5.x
- **Supported Databases:** MySQL / MariaDB and PostgreSQL (uses Moodle's standard Data Manipulation API)
- **Zero External PHP Dependencies:** Communicates with Typesense over HTTPS/JSON using Moodle's built-in `\curl` client — no Composer packages, Java JVM, or PECL C-extensions (`solr`) required.

---

## Documentation Suite

| Guide | Description |
| :--- | :--- |
| **[Architecture & Security Model](./docs/ARCHITECTURE.md)** | Candidate-first pagination, 4-stage permission verification, course & student ID (`idnumber`) priority top-surfacing, Typesense schema, and `pro_bridge`. |
| **[Administrator & Configuration Guide](./docs/ADMIN_GUIDE.md)** | Complete settings reference across all 4 admin tabs, role-aware user identity search, student restrictions, and `mod_videotime` integration. |
| **[Fast NDJSON Indexing & Active Course Scopes](./docs/INDEXING_AND_SCOPES.md)** | Primary-key chunked + pre-joined fast indexers, active-course forum scoping, cohort/date inference, and 100% local attached document extraction. |
| **[Operations, AWS Deployment & Troubleshooting](./docs/OPERATIONS_AND_TROUBLESHOOTING.md)** | 3-minute AWS CloudFormation setup ([`aws/README.md`](./aws/README.md)), RAM sizing, zero-swap collection compaction, and troubleshooting runbook. |
| **[Changelog](./CHANGELOG.md)** | Full release history and upgrade notes. |

---

## Key Features

### 1. Candidate-First Pagination Engine (`\search_typesense\candidate_engine`)
Standard Moodle global search pre-calculates every context a user can access across thousands of courses before sending a query—causing 10+ second timeouts and `128 MB` memory exhaustion on multi-year institutions. `search_typesense` inverts this flow:
- Queries ranked candidate documents from Typesense first (with `drop_tokens_threshold = 0`, `prioritize_token_position = true`, and `max_candidates = 200`).
- Runs Moodle's native `candidate_data()`, `matches_filters()`, `context_allows()`, and `$area->check_access()` strictly on candidate batches until a full authorized page is verified.
- Unauthorized candidates never leave the server, and raw unverified backend result counts are never leaked.

### 2. Course, Template & Student ID (`idnumber`) Priority Top-Surfacing
- **Priority Courses (`prepend_priority_courses()`):** Surfaces accessible matching courses and course templates (`core_course-course`) to the top of Page 1 across categories, with `_` and `-` course code tokenization (`PA105-O`, `_points`, `Template_V2_Points`).
- **Priority User Lookup (`prepend_priority_users()`):** Runs an exact-prefix pass (`num_typos = 0`, `max_candidates = 500`) against `core_user-user` first so numeric student ID prefixes (e.g., typing `9999` for `9999999998`) immediately return the matching student profile at the top of results without being buried under 1-typo phone numbers or forum posts, then falls back to typo-tolerant name matching (e.g., `"Sam Hutchens"` $\rightarrow$ `"Sam Hutchins"`).

### 3. Active vs. Historical Course Scopes & Smart Category Inference (`\search_typesense\course_scope`)
- Separates searches into **`Current courses`**, **`Historical / other courses`**, **`Search everything`**, and (when installed) **`Video Time`**.
- Includes a one-click **Infer Active Categories** admin tool (`infer_categories.php`) that analyzes enrollment velocity, course start/end dates, recent course modifications, and cohort naming patterns (`2605`, `2607`, `FA26`, `SP27`).
- Automatically excludes completed cohort folders (`2602`) and expired courses inside shared active containers (`enddate < now - 14 days`) so current-term searches and active-course forum indexing stay lean and accurate.

### 4. Universal Primary-Key Chunked + Pre-Joined Fast NDJSON Indexer (`\search_typesense\engine`)
- Replaces Moodle core's slow unindexed `ORDER BY timemodified ASC` + per-row `get_fast_modinfo()` (`MAX_CACHED_COURSES = 10` cache thrashing) across all `\core_search\base_activity` plugins (`assign`, `quiz`, `feedback`, `page`, `resource`, `zoom`, `videotime`, `lti`, `customcert`, `book`, `folder`, `forum`, `glossary`, `data`, `lesson`, `scorm`, `survey`, `wiki`, `workshop`, etc.) with `engine::fast_reindex_activity_area()`.
- Pre-joins `{modulename}` with `{course_modules}` and `{context}` in 2,500-row primary-key (`m.id`) chunks (`ORDER BY m.course ASC, m.id ASC`), preloading contexts via `\context_helper::preload_from_record()` and batch-checking `{files}` in a single `SELECT DISTINCT contextid` query per chunk.
- Dedicated fast streamers for **`mod_forum-post`** (restricted automatically to active/current courses via `course_scope::active_indexing_courseids()` so multi-million-row historical discussion archives do not bloat RAM) and **`core_message-*`** (with hooks for Pro smart message noise filtering).
- Live AJAX progress bar (`reindex_areas.php`) with 25-second inline execution cap and automatic handoff to background ad-hoc tasks (`fast_reindex_area_adhoc_task`), plus `\core\session\manager::write_close()` so re-indexing never locks browser sessions.

### 5. Zero-Java 100% Local Attached Document & File Content Indexing (`\search_typesense\file_extractor`)
- Extracts and indexes up to **60,000 characters** of searchable text per document (**30,000 characters** per file, for files up to 20 MB) inside uploaded files (`.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`, `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.vtt`, `.srt`, `.json`) attached to course resources, folders, forums, assignments, and books.
- **100% Local Execution:** Office documents (`.docx`, `.pptx`, `.xlsx`, `.odt`) and caption/text files are parsed natively in PHP (`\ZipArchive` XML), and PDFs use a 3-step local extraction chain (`pdftotext` $\rightarrow$ Moodle's configured Ghostscript `$CFG->pathtogs` $\rightarrow$ built-in pure-PHP `FlateDecode` / `ToUnicode` PDF stream parser).

### 6. Role-Aware User Identity Search & Student Restriction (`\search_typesense\user_profile_fields`)
- Optional indexing and display of user profile fields (`idnumber`, `email`, `phone1`, `phone2`, `department`, `institution`, `city`, `country`, and custom profile fields) gated strictly at both query time and render time by viewer capabilities (`moodle/site:viewuseridentity`, `moodle/user:viewalldetails`).
- `search_typesense/restrictusersearch` (enabled by default) prevents students from querying `core_user-user` or seeing the `Users` tab.

---

## External Service Requirement (Typesense Server)

This plugin integrates with **Typesense** (an open-source search server licensed under GPLv3, or Typesense Cloud):
- **What depends on it:** All global search indexing and search query execution when **Typesense** is selected as the active search engine in **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Manage global search**.
- **If Typesense is not configured or unreachable:** The plugin reports `is_server_ready() = false` on the Manage global search page (with a fail-fast 2-second connect timeout so admin pages never hang) and does not execute searches until a valid Typesense URL and API key are configured.
- **How to obtain and configure Typesense credentials:**
  1. **Option A — Self-Hosted on AWS (3-Minute CloudFormation Stack):** Deploy the included CloudFormation template [`aws/typesense.yaml`](./aws/typesense.yaml) (see [`aws/README.md`](./aws/README.md)) to launch a hardened Amazon Linux 2023 EC2 instance running the native Typesense binary with port `8108` restricted to your Moodle web security group.
  2. **Option B — Local Binary / Self-Hosted Linux:** Download the official single binary from [typesense.org/downloads](https://typesense.org/downloads/) and start it with `--data-dir` and `--api-key`.
  3. **Option C — Typesense Cloud:** Create a cluster at [cloud.typesense.org](https://cloud.typesense.org) and generate an Admin API Key.
  4. Enter the **Typesense URL**, **Port (`8108`)**, and **API Key** under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Connection & Scopes**.

---

## Installation & Quick Start

1. Extract the plugin into `search/engine/typesense/` in your Moodle codebase (or upload the ZIP package via **Site administration $\rightarrow$ Plugins $\rightarrow$ Install plugins**).
2. Visit **Site administration $\rightarrow$ Notifications** (or run `php admin/cli/upgrade.php --non-interactive`) to complete the database upgrade.
3. Configure your Typesense server connection under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Connection & Scopes**.
4. Enable global search and select **Typesense** under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Manage global search**.
5. Detect and apply your active course categories under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Active Course Categories** (`/search/engine/typesense/infer_categories.php`).
6. Build the initial search index via **Fast Area Re-index (NDJSON)** (`/search/engine/typesense/reindex_areas.php` $\rightarrow$ **Run all areas in Background**) or via CLI:
   ```bash
   php search/cli/indexer.php --force
   ```

---

## Privacy & Personal Data Disclosure

`search_typesense` implements Moodle's Privacy API (`\search_typesense\privacy\provider` implementing `\core_privacy\local\metadata\provider`):
- No additional personal data tables are created in the Moodle database.
- **Data sent to the configured Typesense server during indexing:** For each indexed Moodle item (`\search_typesense\engine::map_document()`), the plugin sends the document ID (`id`), search area (`areaid`), `courseid`, `contextid`, author `userid`, `owneruserid`, `groupid`, modification timestamp (`modified`), `title`, `content`, `description1`, `description2` (including normalized `_` / `-` course code tokens), serialized document metadata (`docdata`), and—for user records (`core_user-user`)—any administrator-selected user profile fields (`useridentity`).
- **Attached file content indexing (`search_typesense/fileindexing`):** When `search_typesense/fileindexing` is enabled (default), `content` also includes up to 60,000 characters per document (up to 30,000 characters per file, for files up to 20 MB) of plain text extracted locally on the Moodle server from supported files (`.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`, `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.vtt`, `.srt`, `.json`) attached to indexed Moodle activities and resources.
- **Data sent during search execution:** User search queries (`query`) are transmitted to the configured Typesense server to retrieve matching candidate documents prior to Moodle permission verification.

---

## Optional Commercial Extension (`local_typesensepro`)

`search_typesense` includes an optional extension bridge (`\search_typesense\pro_bridge`) for **Typesense Pro (`local_typesensepro`)**, available through the official [Moodle Marketplace](https://marketplace.moodle.com/plugins/local_typesensepro) for Moodle 4.5+, which adds:
- **Live Type-As-You-Go Navbar InstantSearch** (defaulting to `Search everything` with automatic 0-hit fallback and priority course/student ID lookup)
- **Permission-Safe AI Overviews (RAG)** with clickable `[1] [2]` source citations
- **Scope-Aware Hybrid Semantic Vector Search** (`1536`-dim embeddings)
- **AI Category Classification, Term Auto-Rollover & Historical Intent Detection**
- **Smart Message Noise & Broadcast Filtering**
- **Search Analytics & Zero-Result Insights Dashboard**

---

## Documentation, Support & Issue Tracker

- **Source Code:** [https://github.com/mfgroupllc/moodle-search_typesense](https://github.com/mfgroupllc/moodle-search_typesense)
- **Issue & Bug Tracker (for both `search_typesense` and `local_typesensepro`):** [https://github.com/mfgroupllc/moodle-search_typesense/issues](https://github.com/mfgroupllc/moodle-search_typesense/issues)
- **Moodle Marketplace (Typesense Pro):** [https://marketplace.moodle.com/plugins/local_typesensepro](https://marketplace.moodle.com/plugins/local_typesensepro)
- **License:** GNU GPL v3 or later (see [`LICENSE`](./LICENSE)).
