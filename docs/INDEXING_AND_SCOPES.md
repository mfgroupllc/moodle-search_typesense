# Indexing & Active Course Scopes

This guide explains how to manage search indexing and configure active course scopes on your Moodle site.

---

## 1. Fast Area Re-index (NDJSON)

Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Fast Area Re-index (NDJSON)** to view live index statistics and run high-speed indexing per search area.

### What You See on This Page
- **Total Documents in Collection:** Displays the live total number of documents currently stored in your Typesense collection.
- **Per-Area Status Table:** Lists every enabled Moodle search area (such as *Courses*, *Users*, *Book - chapters*, *Assign - activity information*, *Forum - posts*, *Video Time*, etc.) along with:
  - **Docs in Typesense:** Live document count in Typesense for that specific area.
  - **Newest Document Indexed:** Timestamp of the most recently modified record indexed in that area.
  - **Last Run:** When the area was last indexed, or live status badges (`Queued`, `Running`) while a background task is in progress.

### Indexing Actions
- **Run all areas in Background (Recommended):** Queues background indexing tasks for every enabled search area. Moodle's background cron worker processes each area without browser timeout limits, while an animated progress bar at the top of the page updates document counts automatically every few seconds without refreshing the page.
- **Run in Background (Single Area):** Queues a background re-index for a single search area.
- **Run Inline (Single Area):** Streams records directly for up to 25 seconds and automatically hands off any remaining records to the background task queue so your browser session never times out or locks.
- **Cancel Queued Tasks:** Cancels any pending background re-index tasks waiting in the queue.

### Repairing Missing Context Paths
If courses were deleted in the past and left behind orphaned module records with missing context paths, a warning banner (**Unbuilt Context Paths Detected**) appears at the top of the Fast Area Re-index page. Click **Repair missing context paths** to clean up orphaned records and rebuild missing Moodle context paths in one click.

---

## 2. Active Course Categories & Automatic Date Filtering

Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Active Course Categories** to configure which categories belong to the **Current courses** scope.

### One-Click Active Category Detection
Click **Detect / Re-check Active Categories** to scan your course catalog and pre-select active categories automatically. The detection tool evaluates:
1. **Archive Folder Exclusion:** Skips categories named *Archive*, *Archived*, *Historical*, *Old*, *Past*, *Retired*, *Backup*, *Sandbox*, or *Delete*.
2. **Active & Upcoming Course Dates:** Identifies categories containing courses currently in session or scheduled to start within the next 180 days.
3. **Cohort & Term Naming Patterns (`2605`, `2607`, `FA26`, `SP27`):** Recognizes institutional cohort and term folders and automatically excludes completed cohort folders whose course end dates have already passed—even if students were enrolled in them earlier in the year.
4. **Course Edits & Student Activity:** Measures recent course modifications, student enrollments, and recent learner course accesses.
5. **Granular Subfolder Selection:** When a parent folder (such as *Active*) contains both current cohort subfolders (`2605`) and completed cohort subfolders (`2602`), the tool selects only the active cohort subfolders rather than selecting the entire parent folder.

### Automatic Exclusion of Expired Courses Inside Active Folders
Even inside an active category (such as a shared *Orientation* folder), individual courses whose end date passed more than 14 days ago are automatically excluded from **Current courses** and placed into **Historical / other courses** so learners and faculty only see active courses in their current-term searches.

### Active-Course Forum Post Indexing
When active course categories are configured, **Forum - posts** (`mod_forum-post`) automatically indexes discussions from active courses rather than indexing years of historical discussion archives. This keeps forum search fast and reduces Typesense server memory usage by up to 95% on multi-year institutions.
