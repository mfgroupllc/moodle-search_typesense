# Getting Started Guide

This guide walks through deploying a Typesense server, installing **`search_typesense`** (and optionally **`local_typesensepro`**), configuring active course categories, and running your initial high-speed NDJSON index.

---

## Step 1: Provision Your Typesense Server

`search_typesense` communicates with Typesense v26.0+ over standard HTTPS/JSON using Moodle's built-in `\curl` client. No Java JVM, Composer packages, or PHP PECL extensions are required.

### Option A: Self-Hosted on AWS in 3 Minutes (CloudFormation)
Deploy the included CloudFormation template (`aws/typesense.yaml`) into your existing VPC:

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

This creates an Amazon Linux 2023 EC2 instance (`t3.small` default) running the native `typesense-server` C++ binary under `systemd` (`typesense.service`), generates a 10-year self-signed TLS certificate with your instance's private IP in the SAN, and restricts TCP port `8108` exclusively to your Moodle web security group.

### Option B: Self-Hosted Linux Binary
Download the official single binary from [typesense.org/downloads](https://typesense.org/downloads/) and start `typesense-server` with `--data-dir=/data` and `--api-key=<your-key>`.

### Option C: Typesense Cloud
Provision a managed cluster at [cloud.typesense.org](https://cloud.typesense.org) and generate an Admin API Key.

---

## Step 2: Install the Moodle Plugin(s)

1. **Install `search_typesense` (Required Core Engine):**
   - Extract into `/var/www/html/search/engine/typesense` (or upload via **Site administration $\rightarrow$ Plugins $\rightarrow$ Install plugins**).
2. **Install `local_typesensepro` (Optional Commercial Extension for Moodle 4.5+):**
   - Extract into `/var/www/html/local/typesensepro`.
3. **Run the Moodle Upgrade:**
   ```bash
   sudo -u apache php /var/www/html/admin/cli/upgrade.php --non-interactive
   sudo -u apache php /var/www/html/admin/cli/purge_caches.php
   ```

---

## Step 3: Connect Moodle to Typesense

1. Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Connection & Scopes** (`/admin/settings.php?section=search_typesense`).
2. Enter your **Typesense URL** (e.g., `https://10.0.12.236`), **Port** (`8108`), **API key**, and **Collection name** (`moodle`).
3. Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Manage global search**:
   - Enable **Global search**.
   - Select **Typesense** as the active search engine.

---

## Step 4: Detect & Apply Active Course Categories

Before indexing multi-year sites, configure your active course categories so current-term searches and `mod_forum-post` indexing focus on active courses:

1. Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Active Course Categories** (`/search/engine/typesense/infer_categories.php`).
2. Click **Detect / Re-check Active Categories**.
   - The inference engine analyzes course start/end dates, recent modifications, recent student enrollments, and cohort naming patterns (`2605`, `2607`, `FA26`), automatically excluding completed cohorts (`2602`) and expired courses.
3. Review the checked categories and click **Apply selected categories to Current courses**.

---

## Step 5: Run the Fast NDJSON Area Indexer

1. Navigate to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Fast Area Re-index (NDJSON)** (`/search/engine/typesense/reindex_areas.php`).
2. If the **Unbuilt Context Paths Detected** banner appears at the top of the page, click **Repair missing context paths** first.
3. Click **Run all areas in Background** to queue all enabled search areas onto Moodle's background ad-hoc task runner (`\search_typesense\task\fast_reindex_area_adhoc_task`).
4. Watch the live AJAX progress bar update document counts and timestamps in real time without full-page reloads.
