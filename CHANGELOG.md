# Changelog — `search_typesense`

All notable changes to the **Typesense Global Search Engine (`search_typesense`)** plugin for Moodle are documented in this file.

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
