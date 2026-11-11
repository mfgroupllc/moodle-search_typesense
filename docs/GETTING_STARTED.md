# Getting Started Guide

This guide walks Moodle administrators through setting up a Typesense server, installing **Typesense Global Search** (and optionally **Typesense Pro**), configuring active course categories, and building the initial search index.

---

## Step 1: Set Up Your Typesense Server

Typesense is a fast, lightweight search server that runs as a single binary with no Java runtime or PHP extensions required. Moodle connects to it over standard HTTPS.

### Option A: Deploy on AWS in 3 Minutes (CloudFormation)
If your Moodle site runs on AWS, you can launch a private Typesense server inside your existing VPC using the included CloudFormation template:

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

This launches an Amazon Linux 2023 EC2 instance with encrypted storage, configures TLS automatically, and restricts port `8108` exclusively to your Moodle web servers.

### Option B: Self-Hosted Linux Server
Download the official binary or package from [typesense.org/downloads](https://typesense.org/downloads/) and start the service with a data directory and API key.

### Option C: Typesense Cloud
Create a managed cluster at [cloud.typesense.org](https://cloud.typesense.org) and generate an Admin API Key.

---

## Step 2: Install the Moodle Plugin(s)

1. **Install Typesense Search (`search_typesense`):**
   - Upload the plugin ZIP at **Site administration $\rightarrow$ Plugins $\rightarrow$ Install plugins**, or extract it into `search/engine/typesense/` in your Moodle directory.
2. **Install Typesense Pro (`local_typesensepro` — Optional Add-On for Moodle 4.5+):**
   - Upload the plugin ZIP or extract it into `local/typesensepro/`.
3. **Complete the Moodle Upgrade:**
   - Visit **Site administration $\rightarrow$ Notifications** in your browser (or run `php admin/cli/upgrade.php --non-interactive` via CLI).

---

## Step 3: Connect Moodle to Typesense

1. Go to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Connection & Scopes**.
2. Enter your **Typesense URL**, **Port** (`8108` for self-hosted, `443` for Typesense Cloud), **API key**, and **Collection name** (default: `moodle`), then click **Save changes**.
3. Go to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Manage global search**:
   - Enable **Global search**.
   - Select **Typesense** as the active search engine.

---

## Step 4: Detect Your Active Course Categories

On multi-year Moodle sites, configuring active course categories keeps everyday search focused on current courses while keeping past cohorts accessible under *Historical / other courses*:

1. Go to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Active Course Categories**.
2. Click **Detect / Re-check Active Categories**.
   - The tool reviews course start/end dates, recent course edits, recent student enrollments, and cohort folder names (such as `2605`, `2607`, `FA26`), automatically excluding completed cohort folders and expired courses.
3. Review the recommended categories and click **Apply selected categories to Current courses**.

---

## Step 5: Build Your Search Index

1. Go to **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Fast Area Re-index (NDJSON)**.
2. If an **Unbuilt Context Paths Detected** notice appears at the top of the page, click **Repair missing context paths** first.
3. Click **Run all areas in Background**.
   - Moodle's background task runner will index all enabled search areas while an animated progress bar updates document counts in real time on the page.
