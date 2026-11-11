# Administrator & Configuration Guide (`search_typesense`)

All administration pages for **Typesense (`search_typesense`)** are organized under a single nested category at:
**Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense**

Every page in this category shares a unified Bootstrap navigation bar (`\search_typesense\admin_nav`) providing 1-click switching across all Typesense configuration and operational tools:
1. **Connection & Scopes** (`/admin/settings.php?section=search_typesense`)
2. **Content, Users & Integrations** (`/admin/settings.php?section=search_typesense_content`)
3. **Fast Area Re-index (NDJSON)** (`/search/engine/typesense/reindex_areas.php`)
4. **Active Course Categories** (`/search/engine/typesense/infer_categories.php`)
5. **Typesense Pro (AI & InstantSearch)** (`/admin/settings.php?section=local_typesensepro_settings`, when `local_typesensepro` is installed)
6. **Search Analytics** (`/local/typesensepro/analytics.php`, when `local_typesensepro` is installed)

---

## 1. Tab 1: Connection & Scopes (`search_typesense`)

### 1.1 Typesense Server Connection

| Setting Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `search_typesense/url` | **Typesense URL** | `http://127.0.0.1` | Scheme and host of your Typesense server (e.g., `https://10.0.12.236` or `https://xxx.a1.typesense.net`). |
| `search_typesense/port` | **Port** | `8108` | TCP port of the Typesense HTTP/HTTPS service (`8108` for self-hosted, `443` for Typesense Cloud). |
| `search_typesense/apikey` | **API key** | *(empty)* | Typesense admin or search/index API key (`X-TYPESENSE-API-KEY`). |
| `search_typesense/collection` | **Collection name** | `moodle` | Name of the Typesense collection where Moodle documents are stored. |
| `search_typesense/sslverify` | **Verify SSL certificate** | `0` (Unchecked) | Enable when using a public CA certificate (e.g., Typesense Cloud). Leave unchecked when connecting over a private AWS VPC subnet to a self-signed certificate. |
| `search_typesense/timeout` | **Request timeout (seconds)** | `30` | Maximum HTTP request timeout for bulk indexing and search requests. Note: TCP connection establishment always uses a fail-fast 2-second `CURLOPT_CONNECTTIMEOUT` so Moodle pages never hang if the Typesense server is unreachable. |

### 1.2 Active vs. Historical Course Scopes

| Setting Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `search_typesense/currentcategories` | **Current course categories** | *(empty)* | Multi-select tree of course categories considered **Current / Active**. All subcategories beneath selected categories are included automatically (with completed cohort folders and expired courses filtered out automatically). |
| `search_typesense/defaultscope` | **Default search scope** | `current` | Default tab selected when opening the full Global Search page (`/search/engine/typesense/query.php`): `Current courses`, `Historical / other courses`, or `Search everything`. |

---

## 2. Tab 2: Content, Users & Integrations (`search_typesense_content`)

### 2.1 Attached Document & File Content Indexing

| Setting Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `search_typesense/fileindexing` | **Index attached files & documents** | `1` (Enabled) | Extracts up to 60,000 characters per document (30,000 characters per file, up to 20 MB per file) from `.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`, `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.vtt`, `.srt`, and `.json` files attached to Moodle resources, folders, forums, books, and assignments. |
| `search_typesense/pathtopdftotext` | **Path to `pdftotext`** | `/usr/bin/pdftotext` | Optional path to the `pdftotext` binary (`poppler-utils`) for ultra-fast local PDF text extraction. If blank or not installed, `file_extractor` automatically falls back to Moodle's Ghostscript (`$CFG->pathtogs`) and then to the built-in pure-PHP PDF stream parser. |

### 2.2 Role-Aware User Identity Search & Student Restriction

| Setting Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `search_typesense/restrictusersearch` | **Restrict user profile search to staff** | `1` (Enabled) | When enabled, only users who hold `moodle/site:viewuseridentity`, `moodle/user:viewalldetails`, or `moodle/user:viewhiddendetails` (such as Site Admins, Managers, Teachers, and Non-editing Teachers) can search `core_user-user` or see the **Users** tab. Students cannot search user profiles. |
| `search_typesense/userprofilefields` | **Searchable user profile fields** | `idnumber,email,department,institution` | Standard (`idnumber`, `email`, `username`, `phone1`, `phone2`, `department`, `institution`, `city`, `country`) and custom (`profile_field_*`) user profile fields indexed into `useridentity` and displayed as badges on user search results. |

> **Security Guarantee for User Profile Fields:**
> Even when a field is selected in `search_typesense/userprofilefields`, `user_profile_fields` enforces two independent capability gates:
> 1. **Query-Time Gate (`can_current_user_query_identity()`):** `useridentity` is only included in Typesense `query_by` if the current user holds `moodle/site:viewuseridentity` or `moodle/user:viewalldetails` at the system context.
> 2. **Render-Time Gate (`get_visible_fields_for_user()`):** Each individual profile field badge (e.g., `ID number: 9999999998`, `Email address: ...`) is checked against Moodle's `$CFG->showuseridentity` and custom field visibility rules before rendering.

### 2.3 Video Time (`mod_videotime`) Integration

When `mod_videotime` is installed on your Moodle site, `search_typesense` automatically detects it (`course_scope::videotime_available()`) and displays:

| Setting Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `search_typesense/enablevideotimefilter` | **Show Video Time filter button** | `1` (Enabled) | Adds a dedicated **Video Time** scope button on the search page and inside the InstantSearch dropdown. |
| `search_typesense/videotimelabel` | **Video Time button label** | `Video Time` | Custom label for the Video Time filter tab (for example, `Video Library` or `Culinary Videos`). |

---

## 3. Tab 3: Fast Area Re-index (NDJSON) (`reindex_areas.php`)

Located at `/search/engine/typesense/reindex_areas.php`, this page provides real-time visibility and high-speed NDJSON indexing controls for every enabled Moodle search area:

- **Live Collection Banner:** Displays total documents currently stored in the Typesense collection and the active-course restriction status for `mod_forum-post`.
- **Context Health Banner:** Automatically detects any unbuilt or orphaned `{context}` rows (`path IS NULL OR depth = 0`) and offers a 1-click **Repair missing context paths** button (`engine::repair_unbuilt_contexts()`).
- **Per-Area Status Table:**
  - **Search Area & Component ID:** e.g., `Book - chapters (mod_book-chapter)`, `Forum - posts (mod_forum-post)`.
  - **Docs in Typesense:** Live facet count queried directly from Typesense (`facet_by=areaid`).
  - **Newest Document Indexed:** Timestamp of the newest record indexed in that area.
  - **Last Run:** Formatted timestamp of the last completed indexing pass, plus live progress badges (`Queued`, `Running: X docs (PK cursor #Y)`) when a background task is active.
- **Execution Actions:**
  - **Run in Background (NDJSON):** Queues a `\search_typesense\task\fast_reindex_area_adhoc_task` so Moodle's background cron worker streams the area with zero HTTP timeout limits. While any task is queued or running, an animated Bootstrap progress bar polls `?action=ajaxstatus` every 3 seconds in the background (without reloading the page) and updates row counts in place.
  - **Run Inline:** Streams NDJSON batches synchronously for up to 25 seconds and automatically hands off any remaining primary-key chunks to the background ad-hoc queue.
  - **Cancel Queued Tasks:** Cancels pending `fast_reindex_area_adhoc_task` records in `{task_adhoc}`.

---

## 4. Tab 4: Active Course Categories (`infer_categories.php`)

Located at `/search/engine/typesense/infer_categories.php`, this tool inspects your Moodle category hierarchy, course start/end dates, modification timestamps, and recent student enrollments to recommend which categories belong in **`Current courses`**:

1. Click **Detect / Re-check Active Categories** to run `course_scope::infer_current_categories()`.
2. Review the recommended categories table:
   - Shows each category's full hierarchy path (e.g., `Culinary Arts / Active / 2605`), active course count, active-dated courses, upcoming courses, recent modifications, recent enrollments (last 120 days), recent course accesses (last 60 days), and reason badges.
   - When `local_typesensepro` is installed, also displays AI Classification badges (`AI: 96% Active · Term 2605`, `AI: 89% Upcoming · Term 2607`, `AI: 84% Template`) and plain-English rationales, plus a **Refresh AI Analysis** button.
3. Check or uncheck any category checkboxes and click **Apply selected categories to Current courses** to save `search_typesense/currentcategories`.
