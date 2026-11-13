# Release Notes — Typesense Global Search (`search_typesense`)

All notable updates to the **Typesense Global Search Engine (`search_typesense`)** plugin for Moodle are documented below.

---

## [1.1.13] - 2026-11-13

### Changed
- **Moodle 4.5 LTS Minimum Version (`version.php`):**
  - Raised the minimum supported release to Moodle 4.5 LTS (`2024100700`), which provides the hooks and External Services APIs the plugin relies on, and removed the Moodle 4.1 compatibility shim from `\search_typesense\external\get_reindex_status`.
- **Non-Destructive Context Path Repair (`\search_typesense\engine::repair_unbuilt_contexts`):**
  - **Repair missing context paths** now only asks Moodle's context API to rebuild missing paths. It no longer deletes any course module, block, or context records, and indexing runs no longer trigger the repair automatically.
- **Task Manager API for Background Re-index Queue (`\search_typesense\engine`):**
  - Queued re-index tasks are now listed and cancelled through `\core\task\manager` instead of direct table access. A task that cron is already running is left to finish.
  - Fast Area Re-index no longer clears Moodle's gradual re-index request queue.
- **Privacy API (`\search_typesense\privacy\provider`):**
  - Implemented the plugin and userlist request providers alongside the metadata provider.
- **Release Packaging (`.gitattributes`):**
  - Release archives now contain only the `search_typesense` documentation.

### Fixed
- **Search Page Styles (`styles.css`):**
  - Restored the scope button, search area badge, and user profile field styles that were dropped from `styles.css` in 1.1.12.

---

## [1.1.12] - 2026-11-12

### Changed
- **External Service, AMD Module & Documentation Packaging (`\search_typesense\external\get_reindex_status`, `amd/src/reindex_status.js`, `styles.css`):**
  - Replaced inline `js_init_code` and `action=ajaxstatus` polling on `reindex_areas.php` with a Moodle External Service (`search_typesense_get_reindex_status` in `db/services.php`) and AMD module (`search_typesense/reindex_status`).
  - Moved inline progress bar styling to `styles.css`.
  - Included the `docs/` directory in release `.zip` archives so all relative links in `README.md` resolve offline.

---

## [1.1.11] - 2026-11-11

### Changed
- **Moodle Plugin CI (`moodle-plugin-ci`) Compliance & Workflow:**
  - Resolved all `moodle-plugin-ci codechecker` (`phpcs --standard=moodle`) and `moodle-plugin-ci phpdoc` (`local_moodlecheck`) checks with 0 errors and 0 warnings, and added `.github/workflows/moodle-ci.yml`.

---

## [1.1.10] - 2026-11-11

### Added & Improved
- **Hide Non-Enrollable Courses from Student Search Results (`search_typesense/restrictunenrolledcourses`):**
  - Added a new administration setting under **Content, Users & Integrations $\rightarrow$ Hide non-enrollable courses from student search results** (enabled by default).
  - When enabled, students only see course results (`core_course-course`) for courses they are enrolled in, can already access (such as open guest or auto-enroll courses), or can actively self-enroll in. Historical and cohort-locked courses they cannot join are hidden from students while remaining searchable for staff, faculty, and managers.

---

## [1.1.9] - 2026-11-10

### Added & Improved
- **Priority Student ID (`idnumber`) & User Profile Lookup:**
  - Improved user profile search so typing a numeric student ID prefix (such as `9999` for student number `9999999998`) or a student/staff name immediately surfaces the matching user profile at the top of search results without being buried beneath phone numbers or forum posts.
- **Standardized American (US) English:**
  - Standardized all user-facing language strings and documentation on American (US) English.

---

## [1.1.8] - 2026-11-10

### Added & Improved
- **Smarter Cohort & Course Date Filtering:**
  - Enhanced **Active Course Categories** detection to recognize 4-digit cohort/term folder codes (such as `2602`, `2604`, `2605`, `2607`) and course start/end dates so completed cohorts are excluded automatically even if they had enrollments earlier in the year.
  - Prevents parent `"Active"` folders from selecting completed child cohort folders, and automatically filters out individual expired courses (`enddate` older than 14 days) inside active folders so they appear under **Historical / other courses** instead.
- **Active-Course Forum Post Indexing:**
  - When active course categories are configured, **Forum - posts** (`mod_forum-post`) now indexes only discussions belonging to active courses, reducing forum index size and server memory usage by up to 95% on multi-year Moodle sites.

---

## [1.1.7] - 2026-11-10

### Fixed & Improved
- **Zero Browser Session Locking:**
  - Searching, polling indexing progress, detecting active categories, and running inline re-indexing now release the user's Moodle browser session lock immediately so other browser tabs never wait or stall.
- **Automatic Background Handoff for Inline Re-indexing:**
  - **Run Inline** on the Fast Area Re-index page now runs for up to 25 seconds and automatically hands off any remaining records to the background task queue so web server gateway timeouts never occur.

---

## [1.1.6] - 2026-11-10

### Added & Improved
- **Unified Admin Settings Category:**
  - Grouped all Typesense administration pages under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense**.
- **Live AJAX Indexing Progress Bar:**
  - Replaced the 5-second page refresh on the **Fast Area Re-index** page with a smooth background AJAX progress bar that updates document counts and timestamps in place.
- **Fixed Timestamp Formatting on Fast Area Re-index:**
  - Fixed a date-formatting issue where **Last Run** could display `Dec 31, 1969` instead of the formatted run date.

---

## [1.1.5] - 2026-11-10

### Added & Improved
- **Strict Multi-Term Search Narrowing:**
  - Entering multiple search terms (such as `"PB101 points"`) now strictly narrows results to items containing all entered terms.
- **Fast 2-Second Connection Timeout:**
  - Added a 2-second connection timeout so Moodle admin pages remain responsive even if the Typesense server is offline or rebooting.

---

## [1.1.4] - 2026-11-10

### Added & Improved
- **High-Speed Indexing Across All Course Activities, Forums & Messages:**
  - Dramatically accelerated initial and full re-indexing across all course activity modules, forum posts, and messages, with automatic integration into Moodle's scheduled cron indexer and CLI indexer.
- **Background Re-index Queue & Orphaned Context Repair:**
  - Added **Run all areas in Background** and **Run in Background** actions on the Fast Area Re-index page, plus enhanced **Repair missing context paths** to clean up orphaned module contexts from previously deleted courses.

---

## [1.1.3] - 2026-11-10

### Added
- **Fast Area Re-index Administration Page:**
  - Added a dedicated **Fast Area Re-index (NDJSON)** administration tab displaying live per-area document counts in Typesense and 1-click re-indexing controls.
- **Shared Navigation Tabs Across Admin Pages:**
  - Added a unified tab bar across **Connection & Scopes**, **Content, Users & Integrations**, **Fast Area Re-index**, and **Active Course Categories**.

---

## [1.1.2] - 2026-11-09

### Improved
- **100% Local Attached File Extraction & Privacy Metadata:**
  - Expanded Moodle Privacy API disclosures and ensured all attached document extraction runs 100% locally on the Moodle server.

---

## [1.1.1] - 2026-11-09

### Added
- **Course & Template Top-Surfacing:**
  - Automatically surfaces matching courses and course templates to the top of Page 1, with built-in support for hyphenated and underscored course codes (`PA105-O`, `_points`).
- **Role-Based User Search Privacy:**
  - Added **Restrict user profile search to staff** (enabled by default) so students cannot search user profiles or see the **Users** filter tab.

---

## [1.1.0] - 2026-11-09

### Added
- **Attached Document & Caption Content Indexing:**
  - Added local text extraction and indexing for uploaded `.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`, `.txt`, `.csv`, `.html`, `.vtt`, and `.srt` files attached to course activities and resources.

---

## [1.0.0] - 2026-11-08

### Added
- **Initial Release:**
  - Sub-second, typo-tolerant, accent-insensitive Moodle global search powered by Typesense, featuring **Current courses** vs. **Historical / other courses** scopes, **Active Course Categories** auto-detection, **Video Time (`mod_videotime`)** filter integration, role-aware user identity search, and a 3-minute AWS CloudFormation deployment template.
