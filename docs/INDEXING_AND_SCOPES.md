# Fast NDJSON Indexing & Active Course Scopes (`search_typesense`)

This guide explains how `search_typesense` achieves high-throughput indexing across multi-million-row Moodle databases while keeping the Typesense index lean and memory-efficient.

---

## 1. Why Standard Moodle Global Search Indexing Bottlenecks

In Moodle core's default `\core_search\manager::index()` implementation:
1. **Unindexed Timestamp Scans:** Each activity search area opens a recordset ordered by `ORDER BY timemodified ASC` (an unindexed column on most `mod_*` tables, causing full table sorts).
2. **`get_fast_modinfo()` Cache Thrashing:** For every single activity row, `\core_search\base_activity::get_Document()` calls `get_coursemodule_from_instance()` / `get_fast_modinfo($courseid)`. Because Moodle's `get_fast_modinfo()` static cache only holds **`MAX_CACHED_COURSES = 10` courses**, iterating in `timemodified` order across thousands of courses evicts and rebuilds full course modinfo on almost every row.
3. **Per-Row `{files}` Queries:** When file indexing is enabled, Moodle executes a separate SQL query against `{files}` for every single activity document—even when 95% of activities have zero attached files.
4. **Historical Forum Bloat:** Indexing 10+ years of archived `mod_forum-post` discussions (often 2–4 million rows) consumes gigabytes of RAM in Typesense for discussions from long-completed cohorts.

---

## 2. Primary-Key Chunked + Pre-Joined Fast Indexers

`search_typesense` replaces those bottlenecks with three specialized, primary-key chunked NDJSON streamers inside `\search_typesense\engine`:

### 2.1 Universal Activity Streamer (`engine::fast_reindex_activity_area()`)
Applies automatically to **all** `\core_search\base_activity` plugins (`mod_assign`, `mod_book`, `mod_customcert`, `mod_data`, `mod_feedback`, `mod_folder`, `mod_forum`, `mod_glossary`, `mod_label`, `mod_lesson`, `mod_lti`, `mod_page`, `mod_questionnaire`, `mod_quiz`, `mod_resource`, `mod_scorm`, `mod_survey`, `mod_url`, `mod_videotime`, `mod_wiki`, `mod_workshop`, `mod_zoom`, and any third-party activity plugin):
- Queries `{modulename} m` in **2,500-row primary-key (`m.id > :lastid`) chunks**, pre-joined in SQL with `{modules} md`, `{course_modules} cm`, and `{context} ctx` (`ctx.contextlevel = CONTEXT_MODULE`).
- Calls `\context_helper::preload_from_record($rec)` on every row and stamps `_ts_ctxid` onto the record so `get_document()` resolves the module context in $O(1)$ memory with **zero extra SQL queries**.
- Sorts each 2,500-row chunk in PHP by `(course, id)` before calling `get_document()` and clears `get_fast_modinfo(0, 0, true)` between chunks so worker memory stays flat.
- When attached file indexing is enabled, runs a **single** `SELECT DISTINCT contextid FROM {files} WHERE contextid IN (...) AND filename <> '.'` query per 2,500-row chunk so `$area->attach_files($doc)` is only invoked for activities that actually have uploaded files.

### 2.2 Active-Scoped Forum Post Streamer (`engine::fast_reindex_forum_post_area()`)
- Pre-joins `{forum_posts} fp` $\rightarrow$ `{forum_discussions} fd` $\rightarrow$ `{forum} f` $\rightarrow$ `{course_modules} cm` $\rightarrow$ `{context} ctx` in **5,000-row primary-key (`fp.id`) chunks**.
- **Active-Course Scoping (`course_scope::active_indexing_courseids()`):**
  - When `search_typesense/currentcategories` is configured, `fast_reindex_forum_post_area()` (and incremental `engine::add_document()`) automatically restricts `fd.course` to `course_scope::active_indexing_courseids()` (all active/current courses + `SITEID`).
  - Furthermore, it resolves `MIN(fp.id) - 1` across those active courses first so the PK cursor skips directly past millions of legacy historical forum post IDs in $O(\log N)$ time.

### 2.3 Smart Message Streamer (`engine::fast_reindex_message_area()`)
- Streams `{messages} m` in **5,000-row primary-key (`m.id`) chunks** with batch `CONTEXT_USER` preloading (`\context_helper::preload_from_record()`), avoiding unindexed `ORDER BY timecreated ASC` scans on multi-million-row `{messages}` tables.
- Invokes `pro_bridge::get_message_start_pk()` (using $O(\log N)$ binary PK lookup on `{messages}` to jump directly to the configured retention window, default 180 days) and `pro_bridge::should_index_message()` (skipping automated notifications, trivial 1–2 word replies, and mass broadcast fan-out when `local_typesensepro` is active).

### 2.4 Automatic Interception in CLI & Scheduled Cron
`engine::area_index_starting()` and `engine::add_documents()` automatically detect when Moodle core's scheduled task (`\core\task\search_index_task`) or `search/cli/indexer.php` is running an initial/full index (`_indexingstart <= 0`) or resuming an interrupted PK cursor (`_pkcursor > 0`) and transparently divert execution into `fast_reindex_area()`.

---

## 3. Active Course Category Inference & Date Filtering (`\search_typesense\course_scope`)

### 3.1 How `course_scope::infer_current_categories()` Works
When an administrator runs **Detect / Re-check Active Categories** (`infer_categories.php`) or when `local_typesensepro`'s scheduled rollover task runs, `infer_current_categories()` evaluates every visible category in Moodle using six signals:
1. **Archive Exclusion Patterns:** Immediately excludes categories whose name or path matches archive keywords (`archive`, `archived`, `historical`, `old`, `past`, `inactive`, `retired`, `deprecated`, `backup`, `sandbox`, `test`, `zz_`, `delete`).
2. **Active & Upcoming Course Dates:** Counts courses currently in session (`startdate <= now` and `(enddate == 0 || enddate >= now - 14 days)`) and upcoming courses (`startdate > now` within the next 180 days).
3. **Expired Course Tracking:** Counts courses whose `enddate` passed more than 14 days ago (`enddate > 0 && enddate < now - 14 days`).
4. **Cohort / Term Naming Pattern Verification (`YYMM` / Seasonal Codes):**
   - Detects 4-digit institutional cohort/term folder codes (such as `2602`, `2604`, `2605`, `2607`).
   - If a category is named after a cohort code (e.g., `2602`) and all dated courses inside it have already ended (`activedated == 0`, `upcoming == 0`, `expired > 0`), the category is **excluded** even if students were enrolled in it earlier in the 120-day lookback window.
5. **Recent Modifications, Enrollments & Course Accesses:** Aggregates course modifications (`timemodified >= now - 90 days`), student enrollments (`{user_enrolments}` in the last 120 days), and learner course accesses (`{user_lastaccess}` in the last 60 days).
6. **Smart Subtree Rollup (No Over-Broad Parent Selection):**
   - A parent container (such as `Active`) is only rolled up into a single selection if **none** of its child subcategories are archive folders or completed cohort folders, and its children are not distinct cohort folders (`2604`, `2605`, `2607`). When children represent distinct cohort folders, `infer_current_categories()` selects the specific active cohort folders individually.

### 3.2 Course-Level Expiration Filtering Inside Active Categories
Even inside an active category (such as `Active / Orientation` or `Active / 2604`), older expired course shells may sit alongside current courses.
- `course_scope::courseids('current')` automatically excludes any course with an explicit expired end date (`enddate > 0 && enddate < now - 14 days`).
- `course_scope::courseids('historical')` computes the exact complement (`all visible site courses` minus `current courses`), so expired courses inside active categories remain searchable under **`Historical / other courses`** and **`Search everything`**.

---

## 4. Zero-Java Local Attached Document Extraction (`\search_typesense\file_extractor`)

When `search_typesense/fileindexing` is enabled, `engine::map_document()` passes every supported `\stored_file` attached to a `\core_search\document` through `\search_typesense\file_extractor::extract_text()`:

| File Format | Extensions | Local Extraction Method |
| :--- | :--- | :--- |
| **Word / Writer** | `.docx`, `.odt` | Native PHP `\ZipArchive` XML parser (`word/document.xml`, headers, footers, footnotes, endnotes, `content.xml`). |
| **PowerPoint / Impress** | `.pptx`, `.odp` | Native PHP `\ZipArchive` XML parser across all `ppt/slides/slide*.xml` and speaker notes `ppt/notesSlides/notesSlide*.xml`. |
| **Excel / Calc** | `.xlsx`, `.ods` | Native PHP `\ZipArchive` XML parser (`xl/sharedStrings.xml`, inline worksheet strings, `content.xml`). |
| **Video Captions** | `.vtt`, `.srt` | Strips `WEBVTT` headers, numeric cue IDs, and `00:00:00.000 --> 00:00:05.000` timestamps so spoken lecture transcripts are indexed cleanly. |
| **Text & Data** | `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.json` | Direct UTF-8 text extraction and tag stripping. |
| **PDF Documents** | `.pdf` | **3-Step Local Fallback Chain:**<br>1. `pdftotext -enc UTF-8 -q` (`poppler-utils`)<br>2. Moodle's Ghostscript binary (`$CFG->pathtogs -sDEVICE=txtwrite`)<br>3. Built-in pure-PHP `FlateDecode` + `ToUnicode` CMap stream parser (`extract_pdf_text_native()`). |
