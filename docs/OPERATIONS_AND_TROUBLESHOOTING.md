# Operations, AWS Deployment & Troubleshooting (`search_typesense`)

This guide covers production deployment, memory sizing, collection compaction, CLI maintenance commands, and troubleshooting for `search_typesense`.

---

## 1. Deploying Typesense on AWS (`aws/typesense.yaml`)

The included CloudFormation template ([`aws/typesense.yaml`](https://www.chrismurad.com/moodle-typesense/typesense.yaml), see [`aws/README.md`](https://github.com/mfgroupllc/moodle-search_typesense/blob/main/aws/README.md)) provisions a dedicated Amazon Linux 2023 EC2 instance running the native C++ `typesense-server` binary under `systemd` (`typesense.service`):

```bash
aws cloudformation deploy \
  --template-file aws/typesense.yaml \
  --stack-name moodle-typesense \
  --capabilities CAPABILITY_IAM \
  --parameter-overrides \
    VpcId=vpc-xxxxxxxx \
    SubnetId=subnet-xxxxxxxx \
    MoodleSecurityGroupId=sg-xxxxxxxx \
    TypesenseApiKey='replace-with-a-strong-random-api-key' \
    TypesenseVersion=27.1
```

### Key Production Characteristics
- **No Public Exposure:** Port `8108` is restricted in the EC2 Security Group exclusively to `MoodleSecurityGroupId`. Even when `local_typesensepro` Navbar InstantSearch is enabled, browsers communicate only with Moodle (`/local/typesensepro/ajax_instant.php`), which queries Typesense over your private VPC network.
- **Self-Signed TLS with IP SAN:** The bootstrap script generates a 10-year TLS certificate (`/data/typesense.crt` and `/data/typesense.key`) including `IP:<PrivateIp>,DNS:typesense.moodle` in `subjectAltName`.

---

## 2. Memory Sizing & Keeping Typesense 100% in Physical RAM (No Swap)

Typesense is an **in-memory** search engine: it stores its inverted indexes in RAM for sub-10ms lookups and persists raw documents to `/data/db` (RocksDB) on disk.
- **Why Swap Should Be Avoided:** If the Typesense C++ heap exceeds physical RAM and spills into Linux swap (`swapfile`), prefix searches that touch cold pages will page-fault from disk and jump from `5 ms` to `1,000–2,000 ms`.
- **Recommended Instance Sizing:**

| Active Indexed Documents | Typical Typesense RAM Usage | Recommended EC2 Instance |
| :--- | :--- | :--- |
| Up to **500,000** docs | ~500 MB – 750 MB | `t3.small` (2 GB RAM) |
| **500,000 – 1,000,000** docs | ~800 MB – 1.2 GB | `t3.small` (2 GB RAM) or `t3.medium` (4 GB RAM) |
| **1,000,000 – 3,000,000** docs | ~1.5 GB – 3.5 GB | `t3.medium` (4 GB RAM) or `t3.large` (8 GB RAM) |

### Keeping Index Volume Lean
1. **Configure Active Course Categories (`infer_categories.php`):** Once `search_typesense/currentcategories` is saved, `mod_forum-post` automatically indexes only posts belonging to active courses (`course_scope::active_indexing_courseids()`), preventing millions of archived historical forum posts from inflating RAM.
2. **Enable Smart Message Filtering (with `local_typesensepro`):** Filters out automated notifications, 1–2 word acknowledgments, and mass broadcast fan-out from `core_message-*`.

---

## 3. Fast Collection Compaction Runbook (Zero-Swap Reset)

If a Typesense collection previously indexed millions of historical documents that are no longer needed, deleting millions of documents via HTTP `DELETE ?filter_by=...` does not immediately shrink the C++ allocator's virtual heap until `typesense.service` is restarted.

To compact a collection cleanly in ~3 minutes without using swap:
1. Export the documents you want to keep to `/var/tmp/keep.jsonl` (note: always use `/var/tmp` on root EBS rather than `/tmp`, because `/tmp` on Amazon Linux 2023 is `tmpfs` backed by RAM).
2. Save the collection schema from `GET https://127.0.0.1:8108/collections/moodle` (stripping `num_documents` and `created_at`).
3. Stop `typesense.service`, wipe `/data/db`, `/data/meta`, and `/data/state` (preserving `/data/typesense.crt` and `/data/typesense.key`), and start `typesense.service`.
4. Recreate the collection via `POST /collections` and stream `/var/tmp/keep.jsonl` into `/collections/moodle/documents/import?action=upsert` in 2,500-line batches.

---

## 4. CLI Administration Commands

### Verify Server Readiness & Fast Re-index a Single Area
```bash
sudo -u apache php -r '
define("CLI_SCRIPT", true);
require("/var/www/html/config.php");
$engine = new \search_typesense\engine();
print_r($engine->fast_reindex_area("mod_book-chapter", 250, true));
'
```

### Queue All Enabled Search Areas for Background Cron Indexing
Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Fast Area Re-index (NDJSON)** (`/search/engine/typesense/reindex_areas.php`) and click **Run all areas in Background**.

### Run Pending Background Ad-hoc Re-index Tasks via CLI
```bash
sudo -u apache php /var/www/html/admin/cli/adhoc_task.php \
  --classname='\search_typesense\task\fast_reindex_area_adhoc_task' \
  --execute
```

---

## 5. Troubleshooting Runbook

### Issue 1: Clicking Re-index or Searching Seems to Lock Other Browser Tabs
- **Cause (Fixed in `v1.1.7+`):** PHP's default session handler holds an exclusive lock on the user's session until `write_close()` is called.
- **Resolution:** Ensure you are running `search_typesense` `v1.1.7+`. All entry points (`reindex_areas.php`, `infer_categories.php`, `query.php`, `ajax_instant.php`, and `ajax_overview.php`) call `\core\session\manager::write_close()` before executing Typesense or AI operations.

### Issue 2: Typing a Student ID Prefix (e.g., `9999`) Does Not Show the Matching User
- **Cause (Fixed in `v1.1.9+`):** Typesense's default prefix-expansion candidate cap (`max_candidates = 4`) can be exhausted when forum posts contain numeric tokens starting with the same digits (`99.99%`, `99990`), or 1-typo phone numbers can rank ahead of 10-digit student ID prefixes.
- **Resolution:** Ensure `search_typesense` `v1.1.9+` and `local_typesensepro` `v1.1.7+` are installed, and verify that:
  1. `idnumber` is selected in **Searchable user profile fields** (`search_typesense/userprofilefields`).
  2. The searching user holds `moodle/site:viewuseridentity` or `moodle/user:viewalldetails`.
  3. If using InstantSearch in a restricted scope (`Current courses`), note that InstantSearch defaults to **`Search everything`** (`v1.1.6+`) and automatically falls back to `Search everything` when a restricted scope returns 0 hits.

### Issue 3: "Unbuilt Context Paths Detected" Warning on `reindex_areas.php`
- **Cause:** Orphaned rows in `{course_modules}`, `{block_instances}`, or `{context}` (`path IS NULL OR depth = 0`) left behind by previously deleted courses.
- **Resolution:** Click **Repair missing context paths** at the top of `/search/engine/typesense/reindex_areas.php`. This runs `engine::repair_unbuilt_contexts()`, which removes orphaned context rows whose parent course no longer exists and calls `\context_helper::build_all_paths(false)`.

### Issue 4: "Newest Document Indexed" Shows `Never` or `0` Docs for an Area
- **Cause:** The search area has not yet been indexed into Typesense, or has 0 records in the Moodle database (e.g., an activity module not used in any course).
- **Resolution:** Click **Run in Background** next to the search area on `/search/engine/typesense/reindex_areas.php`. If the Moodle table itself has 0 rows, `Last Run` will update to the current timestamp while `Docs in Typesense` remains `0`.
