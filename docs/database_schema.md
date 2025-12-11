# Kanboard Database Schema Documentation

This document provides a comprehensive overview of the Kanboard database schema, including all tables and their relationships.

## Overview

- **Total Tables:** ~46 tables
- **Schema Version:** 128 (as of latest migration)
- **Supported Databases:** SQLite, MySQL, PostgreSQL, MSSQL
- **Schema Files Location:** `app/Schema/`

---

## Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              USERS                                          │
│  ┌──────────┐                                                               │
│  │  users   │◄─────────────────────────────────────────────────────────┐    │
│  └────┬─────┘                                                          │    │
│       │                                                                │    │
│       ├──────────► group_has_users ◄──────── groups                   │    │
│       │                                                                │    │
│       ├──────────► user_has_metadata                                  │    │
│       ├──────────► user_has_notification_types                        │    │
│       ├──────────► user_has_unread_notifications                      │    │
│       ├──────────► remember_me                                        │    │
│       ├──────────► last_logins                                        │    │
│       ├──────────► password_reset                                     │    │
│       └──────────► sessions                                           │    │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                             PROJECTS                                        │
│  ┌───────────┐                                                              │
│  │ projects  │◄────────────────────────────────────────────────────────┐    │
│  └─────┬─────┘                                                         │    │
│        │                                                               │    │
│   ┌────┴────────────────────┬─────────────────────────────────┐       │    │
│   │                         │                                  │       │    │
│   ▼                         ▼                                  ▼       │    │
│ columns ◄───────────► swimlanes         project_has_users ────┼───► users│
│   │                         │            project_has_groups ───┼───► groups
│   │                         │            project_has_categories│       │    │
│   │                         │            project_has_files     │       │    │
│   ▼                         │            project_has_metadata  │       │    │
│ column_has_restrictions     │            project_has_roles ────┼──┐    │    │
│ column_has_move_restrictions│            project_has_notification_types│    │
│   │                         │            project_daily_stats   │  │    │    │
│   │                         │            project_daily_column_stats    │    │
│   │                         │            project_activities    │  │    │    │
│   │                         │            actions ──► action_has_params │    │
│   │                         │            predefined_task_descriptions  │    │
│   │                         │            custom_filters        │  │    │    │
│   │                         │            invites               │  │    │    │
│   └─────────────────────────┴──────────────────────────────────┘  │    │    │
│                                                                   │    │    │
│              project_role_has_restrictions ◄──────────────────────┘    │    │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                               TASKS                                         │
│  ┌─────────┐                                                                │
│  │  tasks  │◄─── (FK: project_id, column_id, swimlane_id, owner_id)        │
│  └────┬────┘                                                                │
│       │                                                                     │
│       ├──────────► subtasks ──────────► subtask_time_tracking              │
│       ├──────────► comments                                                 │
│       ├──────────► task_has_files                                          │
│       ├──────────► task_has_links ◄──────── links                          │
│       ├──────────► task_has_external_links                                 │
│       ├──────────► task_has_tags ◄───────── tags                           │
│       ├──────────► task_has_metadata                                       │
│       └──────────► transitions                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                          CONFIGURATION                                      │
│  ┌───────────┐     ┌────────────┐     ┌───────────────────────────┐        │
│  │ settings  │     │ currencies │     │ plugin_schema_versions    │        │
│  └───────────┘     └────────────┘     └───────────────────────────┘        │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Core Entity Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `users` | User accounts | id, username, password, email, role, is_active |
| `projects` | Kanban projects | id, name, is_active, is_private, owner_id |
| `tasks` | Task cards | id, title, project_id, column_id, swimlane_id, owner_id |
| `columns` | Board columns | id, title, project_id, position, task_limit |
| `swimlanes` | Horizontal lanes | id, name, project_id, position, is_active |
| `subtasks` | Sub-items within tasks | id, title, task_id, user_id, status |
| `comments` | Task comments | id, task_id, user_id, comment, date_creation |
| `tags` | Labels for tasks | id, name, project_id, color_id |
| `groups` | User groups | id, name, external_id |
| `links` | Link types | id, label, opposite_id |

---

## Complete Table Reference

### User-Related Tables

| Table | Description | Foreign Keys |
|-------|-------------|--------------|
| `users` | Core user accounts | - |
| `groups` | User groups for permissions | - |
| `group_has_users` | User-group membership | users, groups |
| `user_has_metadata` | Key-value user settings | users |
| `user_has_notification_types` | Notification preferences | users |
| `user_has_unread_notifications` | Unread notification queue | users |
| `remember_me` | Remember me tokens | users |
| `last_logins` | Login history | users |
| `password_reset` | Password reset tokens | users |
| `sessions` | Session storage (when using DB handler) | - |

### Project-Related Tables

| Table | Description | Foreign Keys |
|-------|-------------|--------------|
| `projects` | Core project data | - |
| `columns` | Board columns (e.g., Todo, Done) | projects |
| `swimlanes` | Horizontal board lanes | projects |
| `project_has_users` | Project membership | projects, users |
| `project_has_groups` | Group access to projects | projects, groups |
| `project_has_categories` | Task categories | projects |
| `project_has_files` | Project-level attachments | projects |
| `project_has_metadata` | Key-value project settings | projects |
| `project_has_roles` | Custom project roles | projects |
| `project_has_notification_types` | Project notification settings | projects |
| `project_daily_stats` | Daily lead/cycle time stats | projects |
| `project_daily_column_stats` | Daily column task counts | projects, columns |
| `project_activities` | Activity stream | projects, tasks, users |
| `actions` | Automated actions | projects |
| `action_has_params` | Action parameters | actions |
| `custom_filters` | Saved search filters | projects, users |
| `predefined_task_descriptions` | Task templates | projects |
| `invites` | Project invitations | projects |

### Task-Related Tables

| Table | Description | Foreign Keys |
|-------|-------------|--------------|
| `tasks` | Core task data | projects, columns, swimlanes |
| `subtasks` | Task subtasks | tasks, users |
| `subtask_time_tracking` | Time tracking entries | subtasks, users |
| `comments` | Task comments | tasks, users |
| `task_has_files` | Task attachments | tasks |
| `task_has_links` | Internal task links | tasks, links |
| `task_has_external_links` | External URL links | tasks |
| `task_has_tags` | Task-tag associations | tasks, tags |
| `task_has_metadata` | Key-value task data | tasks |
| `transitions` | Column movement history | tasks, columns, users |
| `tags` | Tag definitions | projects |
| `links` | Link type definitions | - |

### Authorization Tables

| Table | Description | Foreign Keys |
|-------|-------------|--------------|
| `project_has_roles` | Custom role definitions | projects |
| `project_role_has_restrictions` | Role restrictions | projects, project_has_roles |
| `column_has_restrictions` | Column-level restrictions | projects, project_has_roles, columns |
| `column_has_move_restrictions` | Column move restrictions | projects, project_has_roles, columns |

### Configuration Tables

| Table | Description | Foreign Keys |
|-------|-------------|--------------|
| `settings` | Application settings (key-value) | - |
| `currencies` | Currency exchange rates | - |
| `plugin_schema_versions` | Plugin migration tracking | - |

---

## Key Relationship Patterns

### Many-to-Many Relationships

```
users ◄──── group_has_users ────► groups
users ◄──── project_has_users ──► projects
groups ◄─── project_has_groups ─► projects
tasks ◄──── task_has_links ─────► tasks (self-referencing via links)
tasks ◄──── task_has_tags ──────► tags
```

### Cascade Delete Behavior

All foreign keys use `ON DELETE CASCADE`:

- **Delete a project** → Deletes all tasks, columns, swimlanes, files, categories, etc.
- **Delete a task** → Deletes all subtasks, comments, files, links, tags, metadata
- **Delete a user** → Deletes their sessions, notifications, time tracking, login history

### Metadata Pattern

Flexible key-value storage for extensibility:

```sql
-- Example: user_has_metadata
CREATE TABLE user_has_metadata (
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    value TEXT DEFAULT '',
    changed_by INTEGER DEFAULT 0,
    changed_on INTEGER DEFAULT 0,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, name)
);
```

Same pattern used for:
- `user_has_metadata`
- `project_has_metadata`
- `task_has_metadata`

---

## Tasks Table Structure (Primary Entity)

The `tasks` table is the most complex and central table:

```sql
CREATE TABLE tasks (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT,
    date_creation INTEGER,
    date_modification INTEGER,
    date_completed INTEGER,
    date_started INTEGER,
    date_due INTEGER,
    date_moved INTEGER,
    color_id TEXT,
    project_id INTEGER,          -- FK: projects
    column_id INTEGER,           -- FK: columns
    swimlane_id INTEGER,         -- FK: swimlanes
    owner_id INTEGER,            -- FK: users (assignee)
    creator_id INTEGER,          -- FK: users (creator)
    category_id INTEGER,
    position INTEGER,
    is_active INTEGER DEFAULT 1,
    score INTEGER,               -- Story points
    priority INTEGER DEFAULT 0,
    time_spent NUMERIC,
    time_estimated NUMERIC,
    reference TEXT,              -- External reference
    recurrence_status INTEGER,
    recurrence_trigger INTEGER,
    recurrence_factor INTEGER,
    recurrence_timeframe INTEGER,
    recurrence_basedate INTEGER,
    recurrence_parent INTEGER,
    recurrence_child INTEGER,
    external_provider TEXT,
    external_uri TEXT
);
```

---

## Migration System

Schema migrations are version-numbered functions in `app/Schema/{Driver}.php`:

```php
const VERSION = 128;  // Current schema version

function version_128(PDO $pdo) {
    $pdo->exec("ALTER TABLE comments ADD COLUMN visibility ...");
}

function version_127(PDO $pdo) {
    $pdo->exec("ALTER TABLE users ADD COLUMN theme ...");
}

// ... back to version_1 (initial schema)
```

- Migrations run automatically on startup if `DB_RUN_MIGRATIONS=true`
- Current version stored in database driver
- Each database driver has its own migration file with identical logic

---

## Indexes

Key indexes for query performance:

```sql
-- User lookups
CREATE UNIQUE INDEX users_username_idx ON users(username);
CREATE INDEX users_admin_idx ON users(is_admin);

-- Project queries
CREATE INDEX columns_project_idx ON columns(project_id);
CREATE INDEX swimlanes_project_idx ON swimlanes(project_id);
CREATE INDEX tasks_project_idx ON tasks(project_id);
CREATE INDEX categories_project_idx ON project_has_categories(project_id);

-- Task queries
CREATE INDEX subtasks_task_idx ON subtasks(task_id);
CREATE INDEX files_task_idx ON task_has_files(task_id);
CREATE INDEX comments_task_idx ON comments(task_id);
CREATE INDEX tasks_reference_idx ON tasks(reference);

-- Activity/transitions
CREATE INDEX transitions_task_index ON transitions(task_id);
CREATE INDEX transitions_project_index ON transitions(project_id);
CREATE INDEX transitions_user_index ON transitions(user_id);
```

---

## Data Types by Database

| Concept | SQLite | MySQL | PostgreSQL |
|---------|--------|-------|------------|
| Primary Key | `INTEGER PRIMARY KEY` | `INT AUTO_INCREMENT` | `SERIAL` |
| Boolean | `INTEGER DEFAULT 0` | `TINYINT(1)` | `BOOLEAN` |
| Text | `TEXT` | `TEXT` / `VARCHAR` | `TEXT` |
| Timestamp | `INTEGER` (Unix) | `INT` | `INT` |
| Decimal | `NUMERIC` / `REAL` | `FLOAT` | `REAL` |

---

## Quick Reference: Table Count by Category

| Category | Count |
|----------|-------|
| User tables | 10 |
| Project tables | 17 |
| Task tables | 11 |
| Authorization tables | 4 |
| Configuration tables | 3 |
| **Total** | **~45** |

---

## Related Files

- Schema definitions: `app/Schema/Sqlite.php`, `Mysql.php`, `Postgres.php`, `Mssql.php`
- Migration helper: `app/Schema/Migration.php`
- Database provider: `app/ServiceProvider/DatabaseProvider.php`
- Models: `app/Model/*.php`

