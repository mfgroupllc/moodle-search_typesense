# Typesense Global Search Engine for Moodle (`search_typesense`)

A high-performance Moodle **global search** engine plugin that indexes your Moodle site into [Typesense](https://typesense.org) and serves sub-second, typo-tolerant, accent-insensitive search at multi-year institutional scale.

- **Component:** `search_typesense`
- **Current Release:** `v1.1.14` (`2026111301`)
- **License:** GNU GPL v3 or later ([`LICENSE`](./LICENSE))
- **Supported Moodle Versions:** Moodle 4.5 LTS and Moodle 5.x
- **Supported Databases:** MySQL / MariaDB and PostgreSQL
- **Zero External PHP Dependencies:** Communicates with Typesense over HTTPS/JSON using Moodle's built-in HTTP client — no Composer packages, Java JVM, or PECL C-extensions (`solr`) required.

---

## Documentation

- **Full Online Documentation Portal:** [https://www.chrismurad.com/moodle-typesense/docs/](https://www.chrismurad.com/moodle-typesense/docs/)
- **[Getting Started Guide](./docs/GETTING_STARTED.md)** — Step-by-step installation, AWS CloudFormation setup, active category detection, and initial indexing.
- **[How Search & Permissions Work](./docs/ARCHITECTURE.md)** — Moodle permission enforcement, what you can search, and active vs. historical scopes.
- **[Administrator Settings Guide](./docs/ADMIN_GUIDE.md)** — Complete guide to all settings tabs, user profile search privacy, and Video Time integration.
- **[Indexing & Active Course Scopes](./docs/INDEXING_AND_SCOPES.md)** — Fast Area Re-index controls, background indexing, and cohort/date category detection.
- **[AWS Setup, Sizing & FAQ](./docs/OPERATIONS_AND_TROUBLESHOOTING.md)** — AWS CloudFormation deployment, RAM sizing table, and administrator FAQ.
- **[Release Notes](./CHANGELOG.md)** — Full version history.

---

## Key Features

- **100% Moodle Permission Enforcement Without Timeouts:** Verifies Moodle course enrollments, activity visibility, separate groups, and role capabilities on search results while avoiding slow context pre-calculation timeouts on large multi-year sites.
- **Course, Template & Student ID (`idnumber`) Top-Surfacing:** Automatically surfaces matching courses, course templates (`PA105-O`, `_points`, `PB101`), and—for authorized staff—student ID numbers (`9999` $\rightarrow$ `9999999998`) and user profiles at the top of search results.
- **Active vs. Historical Course Scopes:** Includes a one-click **Active Course Categories** detection tool that analyzes course start/end dates, recent course edits, enrollment velocity, and cohort folder names (`2605`, `2607`, `FA26`) so users search current terms by default while historical archives stay one click away.
- **Fast Area Re-index & Active-Course Forum Scoping:** Dedicated **Fast Area Re-index (NDJSON)** administration page with background task queueing, live AJAX progress updates, and automatic restriction of forum post indexing to active courses.
- **100% Local Attached File & Caption Indexing:** Extracts and indexes text inside uploaded `.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`, `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.vtt`, `.srt`, and `.json` files locally on your Moodle server.
- **Role-Based User Search Privacy:** Optional indexing and display of user profile fields (`ID number`, `Email address`, `Department`, `Institution`, and custom profile fields) gated strictly by staff capabilities, with student user-profile search restricted by default.
- **Native Video Time (`mod_videotime`) Auto-Detection:** Automatically adds a customizable Video Time filter button when `mod_videotime` is installed.

---

## External Service Requirement (Typesense Server)

This plugin integrates with **Typesense** (an open-source search server licensed under GPLv3, or Typesense Cloud):
- **What depends on it:** All global search indexing and search query execution when **Typesense** is selected as the active search engine in **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Manage global search**.
- **How to obtain and configure Typesense credentials:**
  1. **Option A — Self-Hosted on AWS (3-Minute CloudFormation Stack):** Deploy [`aws/typesense.yaml`](./aws/typesense.yaml) (see [`aws/README.md`](./aws/README.md)) to launch an Amazon Linux 2023 EC2 instance running Typesense with port `8108` restricted to your Moodle web security group.
  2. **Option B — Self-Hosted Linux Binary:** Download the official binary from [typesense.org/downloads](https://typesense.org/downloads/).
  3. **Option C — Typesense Cloud:** Create a cluster at [cloud.typesense.org](https://cloud.typesense.org) and generate an Admin API Key.
  4. Enter the **Typesense URL**, **Port (`8108`)**, and **API key** under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Connection & Scopes**.

---

## Privacy & Personal Data Disclosure

`search_typesense` implements Moodle's Privacy API:
- No additional personal data tables are created in the Moodle database.
- **Data sent to the configured Typesense server during indexing:** Document ID, search area, course ID, context ID, author user ID, owner user ID, group ID, modification timestamp, title, content (including locally extracted text from supported attached files when file indexing is enabled), descriptions, serialized document metadata, and—for user records—any administrator-selected user profile fields.
- **Data sent during search execution:** User search queries are transmitted to the configured Typesense server to retrieve matching items prior to Moodle permission verification.

---

## Optional Commercial Extension (`local_typesensepro`)

**Typesense Pro (`local_typesensepro`)** is available through the official [Moodle Marketplace](https://marketplace.moodle.com/plugins/local_typesensepro) for Moodle 4.5+, adding Live Type-As-You-Go Navbar InstantSearch, Permission-Safe AI Overviews with clickable source citations, Scope-Aware Hybrid Semantic Search, AI Category Classification & Term Auto-Rollover, Smart Message Noise Filtering, and a Search Analytics & Zero-Result Insights Dashboard.

---

## Support & Issue Tracker

- **Online Documentation:** [https://www.chrismurad.com/moodle-typesense/docs/](https://www.chrismurad.com/moodle-typesense/docs/)
- **Source Code:** [https://github.com/mfgroupllc/moodle-search_typesense](https://github.com/mfgroupllc/moodle-search_typesense)
- **Issue & Bug Tracker:** [https://github.com/mfgroupllc/moodle-search_typesense/issues](https://github.com/mfgroupllc/moodle-search_typesense/issues)
- **Moodle Marketplace (Typesense Pro):** [https://marketplace.moodle.com/plugins/local_typesensepro](https://marketplace.moodle.com/plugins/local_typesensepro)
- **License:** GNU GPL v3 or later (see [`LICENSE`](./LICENSE)).
