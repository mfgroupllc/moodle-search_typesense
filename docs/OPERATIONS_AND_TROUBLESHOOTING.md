# AWS Setup, Sizing & FAQ

This guide covers deploying Typesense on AWS, sizing your server for peak performance, and answering common administrator questions.

---

## 1. Deploying Typesense on AWS (CloudFormation)

The included CloudFormation template ([`aws/typesense.yaml`](https://www.chrismurad.com/moodle-typesense/typesense.yaml)) deploys a dedicated Amazon Linux 2023 EC2 instance running Typesense inside your existing AWS VPC in under 3 minutes:

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

### Security Highlights
- **Private VPC Only:** TCP port `8108` is restricted in the EC2 Security Group exclusively to your Moodle web server security group (`MoodleSecurityGroupId`). Your Typesense server is never exposed to the public internet.
- **Built-In TLS:** Automatically generates a 10-year TLS certificate bound to your instance's private IP address.
- **AWS Systems Manager (SSM) Ready:** Includes SSM Session Manager support so no SSH keys or bastion hosts are needed.

---

## 2. Server Memory Sizing Guide

Typesense keeps its search indexes in physical RAM to deliver sub-second searches. To avoid disk swap slowdowns, size your instance so the active document index fits comfortably in physical RAM:

| Active Indexed Documents | Typical RAM Usage | Recommended AWS Instance |
| :--- | :--- | :--- |
| Up to **500,000** documents | ~500 MB – 750 MB | `t3.small` (2 GB RAM) |
| **500,000 – 1,000,000** documents | ~800 MB – 1.2 GB | `t3.small` (2 GB RAM) or `t3.medium` (4 GB RAM) |
| **1,000,000 – 3,000,000** documents | ~1.5 GB – 3.5 GB | `t3.medium` (4 GB RAM) or `t3.large` (8 GB RAM) |

> **Tip for Multi-Year Moodle Sites:** Configure **Active Course Categories** before running your initial index. Restricting forum post indexing to active courses (and enabling **Smart Message Filtering** in Typesense Pro) typically keeps even large multi-year sites under 1,000,000 active documents (~1 GB RAM), allowing them to run smoothly in physical RAM on a `t3.small` or `t3.medium` instance without swap.

---

## 3. Frequently Asked Questions & Troubleshooting

### Why does a searcharea show `0` documents or `Never` in Fast Area Re-index?
Either that search area has not been indexed yet, or your Moodle site has no content in that specific activity module (for example, if your courses do not use *Glossary* or *Wiki* activities). Click **Run in Background** next to the area; once complete, **Last Run** will display the current timestamp.

### Why didn't a student ID number appear when searching?
Check the following:
1. Ensure your Moodle account has staff permissions to view user identity fields (`moodle/site:viewuseridentity` or `moodle/user:viewalldetails`). By default, students are restricted from searching user profiles.
2. Under **Content, Users & Integrations**, confirm that **ID number** is checked under **Searchable user profile fields**.
3. If searching on the full Global Search page while filtered to **Current courses**, switch to **Search everything** (or use the **Users** filter tab). In Typesense Pro's live Navbar InstantSearch (`v1.1.6+`), the dropdown defaults to **Search everything** and automatically falls back to **Search everything** if a restricted scope returns 0 matches.

### Does running a re-index slow down or lock my browser session?
No. All indexing, status polling, category detection, and search pages release your Moodle browser session immediately so you can continue navigating other Moodle pages while indexing runs in the background.

### What happens if the Typesense server is temporarily offline or rebooting?
Moodle uses a fast 2-second connection timeout when contacting Typesense. If the Typesense server is unreachable, Moodle administration pages remain responsive and display a clear connection status message on the **Manage global search** page.
