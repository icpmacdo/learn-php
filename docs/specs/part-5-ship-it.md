# Part 5 — Order system: ship it (act III)

## Goal

Run the part 4 system in production on a single AWS Lightsail server, with the whole path from `git push` to live traffic automated. The skills — provisioning, deploys, secrets, backups — transfer 1:1 to any server anywhere; the managed-AWS-services layer is deliberately skipped.

## What it is

- **One Lightsail Ubuntu instance** (~$10–20/month, flat).
- **Ansible playbooks** that take it from blank image to ready: deploy user + SSH hardening, firewall, Docker, nginx with Let's Encrypt TLS, MySQL and Redis as containers, log rotation. The server is cattle: rebuildable from scratch by playbook alone.
- **Jenkins CD**: on merge to main — build images, run the test suite, push to a registry, deploy over SSH with a blue/green container swap behind nginx for zero downtime, then smoke-test and roll back automatically on failure.
- **Secrets** in Ansible Vault; nothing sensitive in the repo or in images.
- **Backups**: nightly mysqldump shipped off-box + Lightsail snapshots; restore is tested, not assumed.
- **Observability**: centralized on-box logs, container health checks, an external uptime ping that alerts.

## Stack exercised

Ansible (roles, vault, idempotent playbooks), AWS Lightsail, Jenkins (build + deploy pipelines), nginx, Let's Encrypt, Docker in production.

## Learning objectives

- Idempotent provisioning: running the playbook twice changes nothing.
- Why zero-downtime deploys need two of everything for a moment, and how nginx makes the swap invisible.
- Secrets hygiene: the difference between config, secrets, and code — and where each lives.
- Backups are only real once restored: the restore drill is part of the curriculum.
- A runbook: what to do at 2am when it's down.

## Out of scope

RDS/ElastiCache, load balancers, autoscaling, multi-server orchestration, Kubernetes, IAM beyond a deploy user.

## Done when

A merge to main is live within minutes with zero dropped requests (verified by a load probe during deploy); the server can be destroyed and fully rebuilt from playbook + latest backup; the runbook exists and has been walked through once.
