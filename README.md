# Typesense Global Search Engine for Moodle (`search_typesense`)

A high-performance Moodle **global search** engine plugin (`\search_typesense\engine` extending `\core_search\engine`) that indexes your Moodle site into [Typesense](https://typesense.org) and serves sub-second, typo-tolerant, accent-insensitive search.

- **License:** GNU GPL v3 or later (`LICENSE` included).
- **Supported Moodle Versions:** Moodle 4.1 LTS, 4.5 LTS, and Moodle 5.x.
- **Supported Databases:** MySQL / MariaDB and PostgreSQL (uses Moodle's standard Data Manipulation API).
- **Zero External PHP Dependencies:** Communicates with Typesense over HTTPS/JSON using Moodle's built-in `\curl` client — no Composer packages or PECL C-extensions (`solr`) required.

---

## Key Features

- **Candidate-First Pagination Engine:** Avoids loading thousands of course/activity contexts up front on large multi-year sites by pulling top candidate documents from Typesense first and running Moodle's native `check_access()`, group, and context checks strictly on candidate matches.
- **Active vs. Historical Course Scopes:** Includes a one-click **Infer Active Categories** admin tool (`infer_categories.php`) that analyses enrolment velocity, course start/end dates, and recent modifications so users search current terms by default while historical archives stay one click away.
- **Accent, Diacritic & Typo Tolerance:** Built-in Unicode accent folding (`resume` ↔ `résumé`, `naive` ↔ `naïve`, `San Jose` ↔ `San José`) and typo recovery.
- **Native Video Time (`mod_videotime`) Auto-Detection:** Automatically detects when `mod_videotime` is installed and adds a customizable Video Time filter tab on the search page.
- **Zero-Java Attached Document & File Content Indexing:** Extracts and indexes up to 60,000 characters of searchable text per document (30,000 characters per file) inside uploaded files (`.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.txt`, `.csv`, `.vtt`, `.srt`, `.html`) attached to course resources, folders, forums, assignments, and books. All extraction runs 100% locally on your Moodle server: Office documents (`.docx`, `.pptx`, `.xlsx`, `.odt`) and caption/text files are parsed natively in PHP (`\ZipArchive` XML), and PDFs use a 3-step local extraction chain (`pdftotext` $\rightarrow$ Moodle's configured Ghostscript `$CFG->pathtogs` $\rightarrow$ built-in pure-PHP `FlateDecode` / `ToUnicode` PDF stream parser).
- **Role-Aware User Identity Search & Student Restriction:** Optional indexing and display of user profile fields (`idnumber`, `email`, `department`, `institution`, and custom profile fields) gated strictly by viewer capabilities (`moodle/site:viewuseridentity`, `moodle/user:viewalldetails`), plus `search_typesense/restrictusersearch` (enabled by default) restricting `core_user-user` search and hiding the `Users` tab from students.
- **Course & Template Priority Top-Surfacing:** Automatically surfaces matching courses and course templates (`core_course-course`) to the top of Page 1, with built-in `_` and `-` course code tokenization (`PA105-O`, `_points`, `Template_V2_Points`).
- **Bulk NDJSON Batch Indexing:** Indexes 100,000+ Moodle records in under 20 seconds via Typesense's batch import API.

---

## External Service Requirement (Typesense Server)

This plugin integrates with **Typesense** (an open-source search server licensed under GPLv3, or Typesense Cloud):
- **What depends on it:** All global search indexing and search query execution when **Typesense** is selected as the active search engine in **Site administration -> Plugins -> Search -> Manage global search**.
- **If Typesense is not configured or unreachable:** The plugin reports `is_server_ready() = false` on the Manage global search page and does not execute searches until a valid Typesense URL and API key are configured.
- **How to obtain and configure Typesense credentials:**
  1. **Option A — Self-Hosted on AWS (3-Minute CloudFormation Stack):** Deploy the included skeleton CloudFormation template [`aws/typesense.yaml`](./aws/typesense.yaml) (see [`aws/README.md`](./aws/README.md)) to launch a hardened Amazon Linux 2023 EC2 instance running the native Typesense binary with port `8108` restricted to your Moodle web security group.
  2. **Option B — Local Binary / Self-Hosted Linux:** Download the official single binary from [typesense.org/downloads](https://typesense.org/downloads/) and start it with `--data-dir` and `--api-key`.
  3. **Option C — Typesense Cloud:** Create a cluster at [cloud.typesense.org](https://cloud.typesense.org) and generate an Admin API Key.
  4. Enter the **Typesense URL**, **Port (`8108`)**, and **API Key** under **Site administration -> Plugins -> Search -> Typesense**.

---

## Installation

1. Extract the plugin into `search/engine/typesense/` in your Moodle codebase (or upload the ZIP package via **Site administration -> Plugins -> Install plugins**).
2. Visit **Site administration -> Notifications** to complete the database upgrade.
3. Configure your Typesense server connection at **Site administration -> Plugins -> Search -> Typesense**.
4. Enable global search and select **Typesense** under **Site administration -> Plugins -> Search -> Manage global search**.
5. Build the initial search index via scheduled task or CLI:
   ```bash
   php admin/cli/search.php --index --force
   ```

---

## Privacy & Personal Data Disclosure

`search_typesense` implements Moodle's Privacy API (`\search_typesense\privacy\provider` implementing `\core_privacy\local\metadata\provider`):
- No additional personal data tables are created in the Moodle database.
- **Data sent to the configured Typesense server during indexing:** For each indexed Moodle item (`\search_typesense\engine::map_document()`), the plugin sends the document ID (`id`), search area (`areaid`), `courseid`, `contextid`, author `userid`, `owneruserid`, `groupid`, modification timestamp (`modified`), `title`, `content`, `description1`, `description2` (including normalized `_` / `-` course code tokens), serialised document metadata (`docdata`), and—for user records (`core_user-user`)—any administrator-selected user profile fields (`useridentity`).
- **Attached file content indexing (`search_typesense/fileindexing`):** When `search_typesense/fileindexing` is enabled (default), `content` also includes up to 60,000 characters per document (up to 30,000 characters per file, for files up to 20 MB) of plain text extracted locally on the Moodle server from supported files (`.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`, `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.vtt`, `.srt`, `.json`) attached to indexed Moodle activities and resources.
- **Data sent during search execution:** User search queries (`query`) are transmitted to the configured Typesense server to retrieve matching candidate documents prior to Moodle permission verification.

---

## Optional Commercial Extension (`local_typesensepro`)

`search_typesense` includes an optional extension bridge (`\search_typesense\pro_bridge`) for **Typesense Pro (`local_typesensepro`)**, available exclusively through the official [Moodle Marketplace](https://marketplace.moodle.com/plugins/local_typesensepro) for Moodle 4.5+, which adds Live Type-As-You-Go Navbar InstantSearch, Permission-Safe AI Overviews (RAG) with clickable citations, Scope-Aware Hybrid Vector Search, AI Category Classification & Term Auto-Rollover, and a Search Analytics & Zero-Result Insights Dashboard.

---

## Documentation, Support & Issue Tracker

- **Source Code:** [https://github.com/mfgroupllc/moodle-search_typesense](https://github.com/mfgroupllc/moodle-search_typesense)
- **Issue / Bug Tracker (for both `search_typesense` and `local_typesensepro`):** [https://github.com/mfgroupllc/moodle-search_typesense/issues](https://github.com/mfgroupllc/moodle-search_typesense/issues)
- **Moodle Marketplace (Typesense Pro):** [https://marketplace.moodle.com/plugins/local_typesensepro](https://marketplace.moodle.com/plugins/local_typesensepro)
- **License:** GNU GPL v3 or later (see [`LICENSE`](./LICENSE)).
