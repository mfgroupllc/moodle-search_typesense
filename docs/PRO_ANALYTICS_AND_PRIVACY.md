# Search Analytics, Zero-Result Insights & Privacy (`Typesense Pro`)

**Typesense Pro (`local_typesensepro`)** includes an administrative **Search Analytics & Zero-Result Insights Dashboard** to help instructional designers, support teams, and administrators understand what learners and faculty are searching for.

---

## 1. Search Analytics Dashboard

Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Search Analytics** to view search metrics across selectable time windows (**Last 7 days**, **Last 30 days**, or **Last 90 days**).

### Summary Metrics
- **Total Searches:** Total number of searches performed during the selected period.
- **Average & p95 Latency (`ms`):** Average and 95th-percentile search response time in milliseconds.
- **Zero-Result Rate (`%`):** Percentage of searches that returned 0 authorized results.
- **Historical Spillover Queries:** Number of searches performed in **Current courses** that matched past courses in **Historical / other courses**.

### Actionable Reports
1. **Top Zero-Result Queries in Current Courses:** Shows the exact search terms learners and faculty entered that returned 0 results in active courses—making it easy to spot missing syllabus resources, unindexed activity types, or common course code aliases.
2. **Top Historical Spillover Queries:** Highlights terms where users searched in **Current courses** for content located in archived cohorts.
3. **Top Searches Overall:** Lists the most frequent search queries along with search count, average result count, and average response time.

### CSV Report Export
Click **Export CSV** to download the aggregated search analytics report for spreadsheet analysis. All exported text is automatically sanitized against spreadsheet formula injection so files open safely in Microsoft Excel and Google Sheets.

---

## 2. Log Retention & Automatic Cleanup

Under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Typesense Pro (AI & InstantSearch)**:
- **Enable search analytics logging:** Turns search telemetry logging on or off.
- **Analytics retention period (days):** Controls how long search log entries are kept (default: **90 days**). A daily scheduled task automatically purges log records older than the configured retention period.

---

## 3. GDPR & Moodle Privacy Compliance

Both **Typesense Search (`search_typesense`)** and **Typesense Pro (`local_typesensepro`)** implement Moodle's Privacy API (`Site administration -> Users -> Privacy and policies -> Plugin privacy registry`):

- **Subject Data Export & Deletion:** Search analytics logs associated with a user are fully exported and deleted when processing Moodle GDPR data requests.
- **Local Document Extraction:** Attached files (`.pdf`, `.docx`, `.pptx`, `.xlsx`, `.vtt`) are extracted locally on your Moodle server and indexed directly into your configured Typesense server.
- **No Vendor Telemetry:** No search queries, documents, or analytics data are ever transmitted to Chris Murad Consulting, LLC.
