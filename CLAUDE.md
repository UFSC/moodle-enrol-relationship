# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Moodle enrolment plugin `enrol_relationship`. It enrols course users — and creates matching groupings/groups — from the cohorts and groups defined by the `local_relationship` plugin (a hard dependency declared in `version.php`). Adapted from `enrol_cohort`; PHP 5.6 / Moodle ~2.7-era code.

The working directory is the plugin itself; the surrounding tree (`../../`) is a full Moodle install used to host it.

## Running things

- **Manual sync (debug / immediate):** `sudo -u www-data php cli/sync.php [-v]` from this directory, or with the absolute path from Moodle root (`enrol/relationship/cli/sync.php`). Must run as the web server user.
- **Scheduled task sync:** defined in `db/tasks.php` (`minute` = `*/5`); Moodle runs `\enrol_relationship\task\enrol_relationship_sync::execute()` → `enrol_relationship_sync()`.
- No build, lint, or test tooling in this plugin.

## Architecture

### Sync is the entire job

Everything funnels into `enrol_relationship_sync()` in `locallib.php`. It runs eight steps in order, each its own function with the same `($trace, $courseid=NULL, ...)` signature so they can be invoked for one course/user/group or globally:

1. `enrol_users` — enrol users present in `relationship_members` that aren't yet enrolled (or are suspended).
2. `unenrol_users` — unenrol/suspend users no longer in the relationship, per `customint3`.
3. `create_groupings_and_groups` — create the grouping for the relationship and one group per `relationship_groups` row, then link them.
4. `rename_groupings` / `rename_groups` — propagate name changes from the source tables.
5. `unassign_groups_from_groupings` — remove and delete groups whose source `relationship_groups` row was deleted.
6. `add_member_groups` / `remove_member_groups` — reconcile `groups_members`.

The event handlers in `enrol_relationship_handler` (registered in `db/events.php`) call narrow subsets of those same functions for incremental updates. Cron exists as a backstop because events can drop. **When adding behavior, prefer extending the sync functions and let both cron and the event path benefit** — don't add logic only to the event handler.

### The three sync modes (`customint2`)

Stored on the `enrol` table row, set per instance:

- `RELATIONSHIP_SYNC_USERS_AND_GROUPS` (0) — default; full sync.
- `RELATIONSHIP_ONLY_SYNC_GROUPS` (1) — manage group membership only, do not enrol/unenrol. Users must already be enrolled via another plugin; see the `user_enrolment_created` event handler that picks them up.
- `RELATIONSHIP_ONLY_SYNC_USERS` (2) — enrol/unenrol only, no groupings/groups.

Most SQL in `locallib.php` filters with `e.customint2 != :onlysyncgroups` or `!= :onlysyncusers` accordingly. Changing mode semantics means auditing every query.

### Custom field layout on the `enrol` row

- `customint1` — `relationship.id` (the source relationship).
- `customint2` — sync mode (see above).
- `customint3` — removal action: `ENROL_EXT_REMOVED_UNENROL` or `ENROL_EXT_REMOVED_KEEP` (the form only exposes these two; suspend logic exists in `unenrol_users` but is unreachable from the UI).

### Group/grouping idnumber convention

The plugin identifies its own groupings and groups exclusively by `idnumber`:

- Grouping: `relationship_{relationshipid}`
- Group:    `relationship_{relationshipid}_{relationshipgroupid}`

These patterns are hardcoded in SQL `CONCAT(...)` and `LIKE` clauses throughout `locallib.php` and in `lib.php::delete_instance()`. Any change to the format must be made everywhere at once.

### Ownership markers

- `role_assignments.component = 'enrol_relationship'` and `itemid = enrol.id` — used by `unenrol_users` to find what this plugin assigned.
- `groups_members.component = 'enrol_relationship'` and `itemid = enrol.id` — same idea for group membership. `add_member_groups` also reclaims pre-existing `itemid = 0` rows by overwriting the component, so manually-added members get adopted by the plugin.
- `enrol_relationship_allow_group_member_remove()` in `lib.php` returns `false` to block the UI from removing members this plugin owns.

### Where capability checks live

- `enrol/relationship:config` — gate for editing instances (`lib.php`, `edit.php`).
- `enrol/relationship:unenrol` — gate for the unenrol action icon.
- `local/relationship:view` on the relationship's context — required to even see a relationship in the dropdown (`can_add_new_instances`, `edit_form::user_relationships`).
- `moodle/course:managegroups` — gates the group-syncing options in the edit form; without it, only `ONLY_SYNC_USERS` is selectable.
- `edit_form::validation()` additionally blocks creating an instance whose relationship contains a role the current user can't assign in this course (`get_assignable_roles`) — see the `no_enrol_permission` error path.
