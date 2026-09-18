# GLPI plugin: HL API Actors

> [!NOTE]
> **AI-generated code.** This plugin was written with an AI assistant (Claude, by
> Anthropic) working under the direction of the maintainers, who specified the
> behaviour, ran it against a seeded GLPI 11.0.8 with 40 000 tickets and reviewed
> the results. The code, the benchmarks below and the tests in `tools/` come from
> that work. Treat it as you would any other third-party plugin: read it before
> you install it, and report problems in the issue tracker.

Filterable ticket actors, approvals, tasks, followups, pending reasons and user groups for the **GLPI 11 high-level REST API**.

The core API exposes requesters, observers and assignees only through the
computed `team` property, which the RSQL filter engine rejects — there is no
way to ask the API for "tickets assigned to user 42"
([glpi-project/glpi#20743](https://github.com/glpi-project/glpi/issues/20743)).
This plugin adds real join properties to the `Ticket`, `Change` and `Problem`
schemas through the `redefine_api_schemas` hook. No tables, no configuration,
no UI.

## What it adds

| Property | Items | Filterable paths |
|---|---|---|
| `requesters`, `observers`, `assignees` | users | `assignees.id`, `assignees.username`, `assignees.realname`, `assignees.firstname` (same for the other two) |
| `requester_groups`, `observer_groups`, `assignee_groups` | groups | `assignee_groups.id`, `assignee_groups.name`, `assignee_groups.completename` |
| `validations` (Ticket, Change) | approval requests | `validations.status` (1 none, 2 waiting, 3 accepted, 4 refused), `validations.approver_type`, `validations.approver_id`, `validations.requester_id`, `validations.submission_date` |
| `tasks` | tasks | `tasks.technician_id`, `tasks.group_id`, `tasks.state` (0 info, 1 to do, 2 done), `tasks.begin`, `tasks.end`, `tasks.author_id` |
| `followups` | followups | `followups.author_id`, `followups.date`, `followups.is_private` |
| `pending_reasons` | pending reason link | `pending_reasons.reason_id`, `pending_reasons.bump_count` |
| `groups` (on **User**) | groups the user belongs to | `groups.id`, `groups.name` |

Each one is an `x-join` through the actor link table
(`glpi_tickets_users`, `glpi_groups_tickets`, …) with the actor `type` fixed,
the same construction the core uses for `Project.tickets`. The core `team`
property is unchanged.

**On demand (since 1.1.0):** the properties are added to the schema only for
requests whose `filter` or `sort` mentions one of them, and for the OpenAPI
document. Plain lists and single-item reads run the untouched core query, so
they pay nothing; only the requests that use actor filters carry the extra
joins. Consequence: a list response contains the `assignees` / `requesters` …
arrays only when you filtered or sorted by them — use `team` otherwise.

## Examples

```
GET /api.php/Assistance/Ticket?filter=assignees.id==42;status.id=out=(5,6)&sort=date_mod:desc
GET /api.php/Assistance/Ticket?filter=requesters.username==jdoe
GET /api.php/Assistance/Ticket?filter=assignee_groups.id=in=(3,7);date_creation=gt=2026-09-01
GET /api.php/Assistance/Problem?filter=assignees.realname=ilike=*smith*
GET /api.php/Assistance/Ticket?filter=validations.status==2;validations.approver_id==42;status.id=out=(5,6)   # waiting for my approval
GET /api.php/Assistance/Ticket?filter=tasks.technician_id==42;tasks.state==1                            # tickets with my open tasks
GET /api.php/Assistance/Ticket?filter=followups.author_id==42                                          # tickets I followed up
GET /api.php/Administration/User?filter=groups.id==5                                                    # members of a group
```

The properties also appear in each returned item and in `/api.php/doc.json`.

## Install

```
cd /var/www/glpi/plugins          # or the marketplace directory
git clone https://github.com/BTLzdravtech/glpi-hlapiactors.git hlapiactors
```

Then Setup → Plugins → install and enable *HL API Actors*. The directory name
must be `hlapiactors`. Requires GLPI 11.0.x.

## Performance

Measured on GLPI 11.0.8 (Docker, MariaDB 11.8) with 40 000 tickets, 5 400 of them
open, 89 400 actor rows and 200 tickets carrying 15 observers + 3 assignees +
2 groups (`tools/seed-40k.sql`, `tools/glpi-bench.php`; medians of 3 runs
through GLPI's search engine):

| Query | without plugin | plugin 1.0 (always on) | **plugin 1.1 (on demand)** |
|---|---|---|---|
| Total count (`limit=1`, 40 000 tickets) | 36 ms | 249 ms | **35 ms** |
| Open tickets, page of 200, sorted by `date_mod` | 593 ms | 1 116 ms | **610 ms** |
| 200 mass-actor tickets | 356 ms | 747 ms | **360 ms** |
| One ticket | 8 ms | 14 ms | **8 ms** |
| Open tickets assigned to one user (144 hits) | client-side scan of 5 400 | 123 ms | **130 ms** |
| Open tickets with user in any role (grouped OR) | — | — | **312 ms** |
| Count of tickets assigned to one user | — | — | **40 ms** |
| Open tickets waiting for one approver (600 hits) | — | — | **556 ms** |
| Tickets with followups by one user (100 hits) | — | — | **227 ms** |
| Tickets pending for one reason (100 hits) | — | — | **623 ms** |
| Users in one group (40 hits) | 8 ms plain list | — | **15 ms** |

Why always-on cost ~2× per list: GLPI aggregates every selected column per
ticket with `GROUP_CONCAT`, and any join yielding several rows per ticket
(actors average 2–3) multiplies the rows that aggregation processes; a single
combined join costs the same as six. Adding the properties only to requests
that use them removes the penalty from everything else.
- Rights are the core's: the read-rights conditions of the ITIL schemas still
  apply.
- `php tools/check-schema.php` runs the schema function standalone.

## License

GPLv3+, like GLPI.

## Testing against a throwaway GLPI

```bash
docker network create glpitest
docker run -d --name glpitest-db --network glpitest -e MARIADB_ROOT_PASSWORD=root \
  -e MARIADB_DATABASE=glpi -e MARIADB_USER=glpi -e MARIADB_PASSWORD=glpi mariadb:11
docker run -d --name glpitest --network glpitest -e GLPI_DB_HOST=glpitest-db -e GLPI_DB_NAME=glpi \
  -e GLPI_DB_USER=glpi -e GLPI_DB_PASSWORD=glpi -e GLPI_INSTALL_MODE=CONTAINER \
  -v "$PWD:/var/www/glpi/plugins/hlapiactors:ro" glpi/glpi:11.0.8
# wait for the auto-install, then:
docker exec glpitest php bin/console --allow-superuser plugin:install hlapiactors --username=glpi
docker exec glpitest php bin/console --allow-superuser plugin:activate hlapiactors
docker cp tools/glpi-search-test.php glpitest:/tmp/ && docker exec -u www-data glpitest php /tmp/glpi-search-test.php
```

`tools/glpi-search-test.php` creates users, a group and tickets with different
actors and runs GLPI's own search engine on the extended schema; every filter
case must print `OK`.
