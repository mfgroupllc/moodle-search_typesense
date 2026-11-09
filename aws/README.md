# Deploy Typesense on AWS in 3 Minutes (CloudFormation)

Unlike Apache Solr — which requires a Java/JVM runtime, heap tuning, ZooKeeper/schema XML files, and compiling the `solr` PECL C-extension into PHP-FPM on every Moodle web server — **Typesense runs as a single lightweight C++ binary** and Moodle talks to it over standard HTTPS using built-in `curl`.

This repository includes a production-ready skeleton CloudFormation template ([`aws/typesense.yaml`](./typesense.yaml)) that deploys a hardened single-node Typesense server into your existing AWS VPC in under 3 minutes.

## What `aws/typesense.yaml` Creates

- **Amazon Linux 2023 EC2 Instance (`t3.small` default):** Resolves the latest AL2023 AMI automatically in any AWS region via SSM Parameter Store, with IMDSv2 required and an encrypted `gp3` root volume (`/data`).
- **Single Native Binary Under `systemd`:** Downloads `typesense-server`, generates a 10-year self-signed TLS certificate with your instance's private IP in the Subject Alternative Name (SAN), and starts `typesense.service` automatically. No Docker or JVM dependencies.
- **Least-Privilege VPC Security Group:** Opens TCP port `8108` **exclusively** to your Moodle web server security group (`MoodleSecurityGroupId`).
- **SSM Session Manager Access:** Automatically creates an IAM Instance Profile with `AmazonSSMManagedInstanceCore` (or reuses an existing profile if your AWS account enforces IAM guardrails) so no SSH keys or bastion hosts are needed.

## Deploy with 1 Command

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

*(If your AWS account restricts IAM role creation via CloudFormation guardrails, pass `InstanceProfileName=YourExistingSSMProfile` and omit `--capabilities CAPABILITY_IAM`.)*

## Connect Moodle (`search_typesense`)

1. Fetch the stack outputs:
   ```bash
   aws cloudformation describe-stacks \
     --stack-name moodle-typesense \
     --query "Stacks[0].Outputs"
   ```
2. In Moodle, navigate to **Site administration -> Plugins -> Search -> Typesense** and enter:
   - **Typesense URL:** `https://<TypesensePrivateIp>:8108` (or copy `TypesenseEndpoint` from the stack output)
   - **API Key:** The secret passed to `TypesenseApiKey`
3. Build the initial index:
   ```bash
   php admin/cli/search.php --index --force
   ```

## Inspect Logs via AWS Session Manager

```bash
aws ssm start-session --target <TypesenseInstanceId>
sudo journalctl -u typesense -f
```
