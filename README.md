# Coqui Space Manager

Coqui Space integration toolkit — browse, install, manage, and publish skills and toolkits from [coqui.space](https://coqui.space).

## Installation

```bash
composer require coquibot/coqui-space-manager
```

The toolkit is auto-discovered by Coqui via `extra.php-agents.toolkits` in `composer.json`. No additional configuration needed.

## Credentials

| Key | Required | Description |
|-----|----------|-------------|
| `COQUI_SPACE_API_TOKEN` | Optional | API token from coqui.space — enables starring, publishing, account dashboard, collections, reviews, and submissions |
| `COQUI_SPACE_URL` | Optional | Custom API base URL (default: `https://coqui.space/api/v1`) |

Set credentials via the Coqui `credentials` tool:

```
credentials(action: "set", key: "COQUI_SPACE_API_TOKEN", value: "cqs_...")
```

Most read operations work without authentication. Authentication is required for: `star`, `unstar`, `submit`, `publish`, `delete`, `review`, `collections` (create/update/delete), `notifications`, and all `space_account` actions.

## Tools

The toolkit provides three tools (four when authenticated), grouped by entity type:

### `space_skills` — Skill Discovery & Installation

| Action | Parameters | Description |
|--------|-----------|-------------|
| `search` | `query`, `limit?`, `cursor?` | Search skills by keyword |
| `list` | `limit?`, `cursor?`, `sort?`, `tag?` | Browse all skills with filters |
| `details` | `owner`, `name` | Full skill information |
| `versions` | `owner`, `name` | Version history |
| `reviews` | `owner`, `name` | Community reviews |
| `file` | `owner`, `name`, `path` | View a specific file from a skill |
| `install` | `owner`, `name`, `version?`, `force?` | Download and install a skill |
| `update` | `name` | Update an installed skill to latest |
| `publish` | `name`, `description?`, `tags?` | Publish a local skill to coqui.space |
| `delete` | `owner`, `name` | Delete a skill (soft-delete to draft) |
| `log_install` | `owner`, `name` | Log an install event for analytics |

### `space_toolkits` — Toolkit Discovery & Installation

| Action | Parameters | Description |
|--------|-----------|-------------|
| `search` | `query`, `limit?`, `page?` | Search toolkits by keyword |
| `list` | `limit?`, `cursor?`, `sort?`, `tags?` | Browse all toolkits with cursor pagination |
| `popular` | `limit?`, `page?` | Browse popular toolkits |
| `details` | `owner`, `name` or `package` | Full toolkit information |
| `reviews` | `owner`, `name` | Community reviews |
| `install` | `package`, `version?` | Install via Composer |
| `update` | `package` | Update via Composer |
| `publish` | `package`, `description?`, `tags?` | Publish a toolkit to coqui.space |
| `delete` | `owner`, `name` | Delete a toolkit (soft-delete to draft) |

### `space` — Management & Social

| Action | Parameters | Description |
|--------|-----------|-------------|
| `installed` | `type?` (all/skills/toolkits) | List all installed content |
| `disable` | `name` | Deactivate without removing |
| `enable` | `name` | Reactivate disabled content |
| `remove` | `name`, `purge?` | Uninstall (purge=true deletes permanently) |
| `star` | `entity_type`, `owner`, `name` | Star a skill or toolkit |
| `unstar` | `entity_type`, `owner`, `name` | Remove a star |
| `submit` | `type`, `source_url`, `notes?` | Submit a URL for review |
| `tags` | `type?` (all/skills/toolkits) | Browse available tags |
| `search_all` | `query`, `limit?`, `cursor?` | Unified search across skills and toolkits |
| `collections` | `sub_action`, `collection_id?`, `collection_name?`, `description?`, `is_public?`, `entity_type?`, `entity_id?` | Manage collections (sub_actions: list, create, details, update, delete, add_item, remove_item) |
| `review` | `entity_type`, `owner`, `name`, `rating`, `title?`, `body?` | Post a review for a skill or toolkit |
| `notifications` | `sub_action`, `notification_id?`, `unread?` | Manage notifications (sub_actions: list, mark_read, mark_all_read) |
| `health` | — | Check API health status |

**Identifier convention:** Names containing `/` are treated as toolkits (Composer packages). Names without `/` are treated as skills (directory names).

### `space_account` — Account Dashboard (authenticated only)

Only available when `COQUI_SPACE_API_TOKEN` is set.

| Action | Parameters | Description |
|--------|-----------|-------------|
| `profile` | — | Your profile info (role, verified status) |
| `my_skills` | `limit?`, `cursor?` | List your published skills |
| `my_toolkits` | — | List your published toolkits |
| `my_collections` | — | List your collections |
| `my_submissions` | — | List your submissions |
| `my_installs` | `limit?`, `cursor?` | Install activity log |
| `my_analytics` | `days?` | Download/star analytics with daily chart |
| `my_stars` | `limit?`, `cursor?` | Your starred items |

## Standalone Usage

```php
<?php

use CoquiBot\SpaceManager\SpaceManagerToolkit;

// From environment variables
$toolkit = SpaceManagerToolkit::fromEnv();

// Or with explicit configuration
$toolkit = new SpaceManagerToolkit(
    urlResolver: static fn() => 'https://coqui.space/api/v1',
    tokenResolver: static fn() => getenv('COQUI_SPACE_API_TOKEN') ?: '',
    workspaceDir: '/path/to/.workspace',
);

// Get tools
foreach ($toolkit->tools() as $tool) {
    echo $tool->name() . ': ' . $tool->description() . "\n";
}

// Execute a tool directly
$tool = $toolkit->tools()[0]; // space_skills
$result = $tool->execute(['action' => 'search', 'query' => 'code review']);
echo $result->content;
```

## Gated Operations

The following operations require user confirmation (unless `--auto-approve` is enabled):

- `space_skills`: install, update, publish, delete
- `space_toolkits`: install, update, publish, delete
- `space`: disable, enable, remove, submit, review, collections (create/update/delete/add_item/remove_item)

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Static analysis
composer analyse
```

## Architecture

```
src/
├── Api/
│   └── SpaceClient.php          # HTTP client for all API endpoints
├── Config/
│   └── SpaceRegistry.php        # Constants, excluded packages, helpers
├── Installer/
│   ├── SkillInstaller.php       # Skill lifecycle (ZIP download/extract)
│   └── ToolkitInstaller.php     # Toolkit lifecycle (Composer-based)
├── Tool/
│   ├── SpaceAccountTool.php     # space_account tool (8 actions, auth-only)
│   ├── SpaceSkillsTool.php      # space_skills tool (11 actions)
│   ├── SpaceToolkitsTool.php    # space_toolkits tool (9 actions)
│   └── SpaceManageTool.php      # space tool (14 actions)
└── SpaceManagerToolkit.php      # ToolkitInterface entry point
```

## License

MIT
