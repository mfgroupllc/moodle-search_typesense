# Changelog — `search_typesense`

All notable changes to the **Typesense Global Search Engine (`search_typesense`)** plugin for Moodle are documented in this file.

---

## [1.1.6] - 2026-11-10 (`2026111003`)

### Fixed & Improved
- **Nested Admin Settings Category (`settings.php`, `lang/en/search_typesense.php`):**
  - Grouped all Typesense admin settings pages (`Connection & Scopes`, `Content, Users & Integrations`, `Fast Area Re-index (NDJSON)`, `Active Course Categories`, and `Typesense Pro (AI & InstantSearch)`) inside a dedicated **Typesense** (`search_typesense_category`) category under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search**, matching Moodle core's **Elasticsearch** (`search_elastic`) and **Atto HTML editor** (`editor_atto`) layout.
- **Fixed `Dec 31, 1969` Display in "Newest Document Indexed" (`reindex_areas.php`):**
  - Fixed double-formatting of `$cfg->lastindexrun` on `reindex_areas.php` where `\core_search\manager::get_areas_config()` already returns a formatted date string (or `'Never'`), preventing `(int)$cfg->lastindexrun` from evaluating to `0` (`Wednesday, December 31, 1969, 6:00 PM`).
- **Fixed Inline Progress Tracker Lifecycle & Added Live AJAX Status Updates (`\search_typesense\engine::fast_reindex_area`, `reindex_areas.php`):**
  - Added `$progress->start_progress()` and `$progress->end_progress()` (in `finally`) inside `engine::fast_reindex_area()` so running an inline **Run Inline** on PK-chunked activity/forum search areas never triggers `Coding error detected: progress() without start_progress`.
  - Replaced the 5-second full-page reload on `reindex_areas.php` with an asynchronous AJAX status poller (`action=ajaxstatus`), an animated Bootstrap progress bar, and live in-place updates for the **Docs in Typesense**, **Newest Document Indexed**, and **Last Run** columns.

---

## [1.1.5] - 2026-11-10 (`2026111002`)

### Added & Refined
- **Strict `AND` Multi-Term Search Narrowing (`\search_typesense\candidate_engine`):**
  - Configured `drop_tokens_threshold => 0` and `prioritize_token_position => 'true'` in `candidate_params()` and `prepend_priority_courses()`, with `query_by_weights => '12,10,3,1'` on `title,description2,description1,content` for Course searches so adding additional search terms (e.g. `"PB101 points"`, `"PA221 points"`) strictly narrows results to documents containing **all** terms rather than falling back to `OR`.
- **Fail-Fast HTTP Connect Timeout (`\search_typesense\client`):**
  - Added a 2-second `CURLOPT_CONNECTTIMEOUT` so Moodle admin pages never block if the Typesense server is rebooting or unreachable.
- **Pro Extension Hooks for Smart Message Filtering & Guided Discovery (`\search_typesense\pro_bridge`, `engine::fast_reindex_message_area`, `query.php`):**
  - Added `pro_bridge::get_message_start_pk()` and `pro_bridge::should_index_message()` hooks so `local_typesensepro` can filter out automated notification noise, trivial 1-word replies, and bulk broadcast fan-out during message indexing while keeping standard unfiltered Moodle behavior in the standalone free plugin.

---

## [1.1.4] - 2026-11-10 (`2026111001`)

### Added & Fixed
- **Universal Primary-Key Chunked + Pre-Joined Fast Indexer Across All Activity Plugins (`\search_typesense\engine`):**
  - Replaced Moodle core's slow unindexed `ORDER BY timemodified ASC` + per-row `get_fast_modinfo()` (`MAX_CACHED_COURSES = 10` cache thrashing) + per-row `{files}` lookups across **all** `\core_search\base_activity` plugins (`assign`, `quiz`, `feedback`, `page`, `resource`, `zoom`, `videotime`, `lti`, `customcert`, and any newly installed `mod_*` activity plugins) with `engine::fast_reindex_activity_area()`:
    - Streams `{modulename}` in 2,500-row primary-key (`m.id`) chunks pre-joined with `{course_modules}` and `{context}` (`ORDER BY m.course ASC, m.id ASC`), preloading contexts via `\context_helper::preload_from_record()` and stamping `_ts_ctxid` with zero `get_fast_modinfo()` calls.
    - Performs a single `SELECT DISTINCT contextid FROM {files} WHERE contextid IN (...)` query per 2,500-row chunk so `$fs->get_area_files()` is only called for activities that actually have attached files.
  - Added dedicated PK-chunked + pre-joined streamers for `mod_forum-post` (`engine::fast_reindex_forum_post_area()`, 5,000-row PK chunks pre-joined with `{forum_discussions}`, `{forum}`, `{course_modules}`, and `{context}`) and `core_message-*` (`engine::fast_reindex_message_area()`, 5,000-row PK chunks with batch user context preloading, bypassing unindexed `ORDER BY timecreated ASC` full-table scans on multi-million-row `{messages}` tables).
  - **Automatic CLI / Cron Interception (`engine::area_index_starting`, `engine::add_documents`):** Automatically intercepts initial/unindexed area runs (`_indexingstart <= 0`), interrupted PK-cursor resumptions (`_pkcursor > 0`), and full reindexes during standard Moodle scheduled tasks (`\core\task\search_index_task`) and `search/cli/indexer.php` so any newly installed plugin or unindexed search area uses the fast PK-chunked streamer automatically.
- **Background Ad-hoc Task Queue & Live Progress Tracking (`\search_typesense\task\fast_reindex_area_adhoc_task`, `reindex_areas.php`):**
  - Added **Run in Background (NDJSON)** (`fast_reindex_area_adhoc_task`) and **Run all areas in Background** actions on `reindex_areas.php` so large search areas run via Moodle's CLI cron runner with zero Apache `mod_proxy_fcgi` HTTP gateway timeouts.
  - Persists `_docsprocessed`, `_recordsprocessed`, `_lastindexrun`, and `_pkcursor` after every 250–500 document NDJSON batch and displays a live auto-refreshing status banner on `reindex_areas.php`.
- **Orphaned `{context}` & `{course_modules}` Cleanup (`\search_typesense\engine::repair_unbuilt_contexts`):**
  - Enhanced **Repair missing context paths** to detect and delete orphaned `{course_modules}`, `{block_instances}`, and `{context}` rows (`path IS NULL OR depth = 0`) whose parent course or parent context was previously deleted from `{course}`, permanently resolving persistent unbuilt context warnings.

---

## [1.1.3] - 2026-11-10 (`2026111000`)

### Added & Fixed
- **Fast Bulk NDJSON Search Area Re-indexer (`reindex_areas.php`, `\search_typesense\engine::fast_reindex_area`):**
  - Added a dedicated **Typesense Fast Area Re-index (NDJSON)** administration page (`/search/engine/typesense/reindex_areas.php`) showing live per-area document counts in Typesense and 1-click **Fast Re-index Now (NDJSON)** and **Queue for Next CLI / Cron** actions that bypass Moodle core's slow context-by-context `{search_index_requests}` queue.
  - Added automatic detection and 1-click repair (`\context_helper::build_all_paths(false)`) for unbuilt/orphaned `{context}` rows (`path IS NULL OR depth = 0`) that otherwise cause Moodle core's `searchreindex.php` to fail with `moodle_database::get_in_or_equal() does not accept empty arrays`.
- **Indexer Memory Exhaustion Fix (`\search_typesense\engine::index_starting`, `area_index_starting`, `area_index_complete`, `add_document_batch`):**
  - Automatically raises PHP memory limit via `raise_memory_limit(MEMORY_HUGE)` (2GB) prior to opening search area recordsets (`get_recordset_by_timestamp`), clears per-area `activitiesdata` and static `get_fast_modinfo(0, 0, true)` caches between batches/areas, and adjusts batch size to 250 documents when attached file indexing is active.
- **Modular Admin Sub-Pages & Shared Navigation Tabs (`\search_typesense\admin_nav`, `settings.php`):**
  - Split administration settings into clean sub-pages under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search** (`Connection & Scopes`, `Content, Users & Integrations`, `Fast Area Re-index (NDJSON)`, and `Active Categories`) with a unified Bootstrap tab bar.

---

## [1.1.2] - 2026-11-09 (`2026110902`)

### Added & Hardened
- **Privacy API Completeness & 100% Local Attached-Document Extraction (`\search_typesense\privacy\provider`, `\search_typesense\file_extractor`):**
  - Declared all indexed Typesense document fields (`userid`, `owneruserid`, `groupid`, `courseid`, `contextid`, `modified`, `title`, `content`, `description1`, `description2`, `docdata`, `useridentity`) and search queries (`query`) in `\search_typesense\privacy\provider::get_metadata()`, including explicit disclosure of locally extracted attached file text (`search_typesense/fileindexing`, up to 60,000 characters per document).
  - Removed the optional external `tikaurl` HTTP endpoint from `file_extractor` and `settings.php` so attached document extraction runs 100% locally on the Moodle server (`ZipArchive` XML, `pdftotext`, `$CFG->pathtogs` Ghostscript, and native PHP PDF stream parsing).

---

## [1.1.1] - 2026-11-09 (`2026110901`)

### Added
- **Course & Template Priority Top-Surfacing (`\search_typesense\candidate_engine::prepend_priority_courses`):**
  - Automatically surfaces accessible matching courses and course templates (`core_course-course`) to the top of Page 1 across categories.
  - Enabled `core_course-course` search visibility via `\core_course_category::can_view_course_info($course)` even when Moodle's global `$CFG->searchincludeallcourses` is disabled.
- **Course Code `_` & `-` Tokenization (`\search_typesense\engine`, `\search_typesense\client`):**
  - Added `'token_separators' => ['_', '-', '/', '.']` to the Typesense schema and expanded underscore/hyphen-delimited course titles and shortnames (`PA105-O`, `_points`, `Template_V2_Points`) into `description2`.
- **Role-Based User Search Restriction (`\search_typesense\user_profile_fields::can_current_user_search_users`):**
  - Added `search_typesense/restrictusersearch` setting (enabled by default) restricting `core_user-user` search and hiding the `Users` tab from students who do not hold `moodle/site:viewuseridentity`, `moodle/user:viewalldetails`, or `moodle/user:viewhiddendetails`.
- **Zero-Result Sticky Category Fallback (`query.php`):**
  - Automatically falls back to `core-all` when a new search submitted from a specific category tab (such as `Users`) returns 0 results in that category but matches records across all areas.

### Fixed
- **Manager Memory Exhaustion & Multi-Batch Timeout Fix (`\search_typesense\access_manager`):**
  - Scoped `access_manager::eligible_courses(true)` to evaluate `can_access_course()` strictly on candidate course batches instead of calling `parent::get_my_courses(true)` across all site courses, eliminating `128MB` memory exhaustion for system-level Managers and 10-second multi-batch timeouts.

---

## [1.1.0] - 2026-11-09 (`2026110900`)

### Added
- **Zero-Java Attached Document & File Content Indexing (`\search_typesense\file_extractor`):**
  - Enabled Moodle core attached-file indexing (`engine::file_indexing_enabled()`) so uploaded files attached to course resources, folders, forums, assignments, and books are automatically extracted and indexed into Typesense.
  - **Office OpenXML & OpenDocument (`.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`):** Native PHP `\ZipArchive` XML extraction across document bodies, headers/footers/footnotes, presentation slides, speaker notes, and shared spreadsheet strings.
  - **Plain Text, Markup & Video Captions (`.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.vtt`, `.srt`, `.json`):** Direct text extraction with automatic stripping of `WEBVTT` / SRT cue headers and timestamps so spoken video captions are indexed cleanly.
  - **4-Step Automatic PDF Extraction Chain (`.pdf`):**
    1. Optional external Apache Tika server (`search_typesense/tikaurl`) if configured.
    2. `pdftotext` CLI (`poppler-utils`) via `search_typesense/pathtopdftotext` (`admin_setting_configexecutable`) or auto-discovered alongside Moodle's `$CFG->pathtopdftoppm`.
    3. Moodle's configured Ghostscript path (`$CFG->pathtogs`) using `-sDEVICE=txtwrite`.
    4. Built-in pure-PHP PDF stream and `ToUnicode` CMap parser (`file_extractor::extract_pdf_text_native()`) that decompresses `FlateDecode` streams and decodes both literal `(...) Tj/TJ` and CID `<hex> Tj/TJ` text operators with zero external binaries required.
- **Admin Settings (`settings.php`):** Added *Attached document & file content indexing* section (`fileindexing`, `pathtopdftotext`, `tikaurl`).
- **PHPUnit Tests (`tests/file_extractor_test.php`):** Added unit tests covering `.docx`, `.pptx` (slides + speaker notes), `.vtt` captions, `FlateDecode` PDF streams, and `ToUnicode` CMap hex PDF streams.

---

## [1.0.0] - 2026-11-08 (`2026110802`)

### Added
- **Core Typesense Search Engine (`\search_typesense\engine`):**
  - Drop-in Moodle global search engine (`\core_search\engine`) communicating with Typesense over HTTPS/JSON using Moodle's native `\curl` client (zero PECL extensions or Java JVM required).
  - Bulk NDJSON batch indexing (`supports_add_document_batch()`, `add_document_batch()`) capable of indexing 100,000+ Moodle records in under 20 seconds.
  - Built-in Unicode accent/diacritic folding (`resume` matches `résumé`, `naive` matches `naïve`) and typo tolerance.
- **Candidate-First Pagination Engine (`\search_typesense\candidate_engine` & `\search_typesense\access_manager`):**
  - Eliminates 10+ second context pre-calculation timeouts on large multi-year Moodle instances by pulling top candidate hits from Typesense first and running Moodle's native `check_access()`, group, and context checks strictly on candidate matches.
- **Active vs. Historical Course Scopes (`\search_typesense\course_scope` & `infer_categories.php`):**
  - Separates searches into `Current courses`, `Historical / other courses`, and `Search everything` tabs.
  - Includes a 1-click **Detect / Re-check Active Categories** admin tool analysing course start/end dates, recent course modifications, upcoming/in-development shells, and student enrolment velocity.
- **Native Video Time (`mod_videotime`) Auto-Detection:**
  - Automatically detects when `mod_videotime` is installed and adds a customizable Video Time filter button and result tab.
- **Role-Aware User Identity Search (`\search_typesense\user_profile_fields`):**
  - Indexes and displays standard (`idnumber`, `email`, `department`, `institution`, etc.) and custom user profile fields strictly gated at query and render time by Moodle viewer capabilities (`moodle/site:viewuseridentity`, `moodle/user:viewalldetails`).
- **1-Command AWS CloudFormation Template (`aws/typesense.yaml`):**
  - Deploys a hardened Amazon Linux 2023 EC2 (`t3.small`) Typesense instance into an existing VPC with port `8108` restricted to the Moodle web security group and encrypted `gp3` storage.
- **GDPR Privacy Provider (`\search_typesense\privacy\provider`):**
  - Full `\core_privacy\local\metadata\provider` implementation documenting external Typesense index fields.
