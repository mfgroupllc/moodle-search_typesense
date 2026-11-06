# Deploy Typesense on AWS (CloudFormation)

A single-instance Typesense server for the `search_typesense` Moodle plugin,
deployed into an existing VPC/subnet (e.g. the **Escoffier `moodle-stage`**
environment). Typesense runs as a native binary under systemd — no Docker, no
container registry dependency.

## What it creates

- One EC2 instance (Amazon Linux 2023) running `typesense-server`.
- A security group that opens **8108 only to the Moodle security group**.
- An IAM instance profile for **SSM Session Manager** access (no SSH key needed).
- Data lives on the instance root volume (`/data`).

## Prerequisites

- The target subnet must have **internet egress** so the instance can download
  the Typesense binary from `dl.typesense.org` (and, if `TypesenseVersion=latest`,
  query `api.github.com`).
- You need the **Moodle web server security group id** so only Moodle can reach 8108.

## Deploy

```bash
aws cloudformation deploy \
  --template-file aws/typesense.yaml \
  --stack-name moodle-typesense-stage \
  --capabilities CAPABILITY_IAM \
  --parameter-overrides \
    VpcId=vpc-xxxxxxxx \
    SubnetId=subnet-xxxxxxxx \
    MoodleSecurityGroupId=sg-xxxxxxxx \
    TypesenseApiKey='a-strong-secret-key' \
    TypesenseVersion=27.1
```

Use `TypesenseVersion=latest` to grab the newest release at deploy time (pin it
for production).

## After deployment

1. Read the outputs:
   ```bash
   aws cloudformation describe-stacks --stack-name moodle-typesense-stage \
     --query "Stacks[0].Outputs"
   ```
   The `TypesenseEndpoint` output is what Moodle needs, e.g. `http://10.0.x.x:8108`.
2. In Moodle, set the **Typesense** search engine and configure:
   - **Host:** the private IP from the output
   - **Port:** `8108`
   - **Protocol:** `http` (in-VPC; add an ALB + ACM for TLS later if required)
   - **API key:** the same value passed to `TypesenseApiKey`
3. Run the global search index.

## Access the box

```bash
aws ssm start-session --target <TypesenseInstanceId>
journalctl -u typesense -f   # watch the server log
```

## Notes / next steps

- For production, attach a **dedicated EBS** (vs. the root volume) and/or move to
  a multi-node Typesense cluster with a load balancer.
- Add **TLS** via an ALB + ACM if Moodle will call Typesense across a public/VPN boundary.
- The `latest` version resolution requires GitHub API access from the instance at
  deploy time; pin a version to avoid surprises.
