# ADR 0004 — Run with PHP + MySQL only

- Status: accepted
- Date: 2026-10-17

## Context
Target users run small VPSs and shared-style servers; many self-hosted tools require Redis,
ClickHouse, Kafka or Docker, which raises the adoption barrier.

## Decision
The collector requires only PHP 8.3+, MySQL 8 / MariaDB 10.6+, one cron line and one queue worker
(`database` queue driver). Docker Compose is provided as a convenience, not a requirement.

## Consequences
+ Installs on any Laravel-capable server.  − Not built for millions of events per minute; a future
storage adapter (e.g. ClickHouse) can be added behind an interface if needed.
