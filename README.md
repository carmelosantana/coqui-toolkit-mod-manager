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
| `COQUI_SPACE_API_TOKEN` | Optional | API token from coqui.space — enables starring, publishing, and submissions |
| `COQUI_SPACE_URL` | Optional | Custom API base URL (default: `https://coqui.space/api/v1`) |

Set credentials via the Coqui `credentials` tool:

```
credentials(action: "set", key: "COQUI_SPACE_API_TOKEN", value: "cqs_...")
```

Most read operations work without authentication. Authentication is required for: `star`, `unstar`, `submit`, `publish`.

## Tools

The toolkit provides three tools, grouped by entity type:

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

**Identifier convention:** Names containing `/` are treated as toolkits (Composer packages). Names without `/` are treated as skills (directory names).

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

- `space_skills`: install, update, publish
- `space_toolkits`: install, update, publish
- `space`: disable, enable, remove

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
│   ├── SpaceSkillsTool.php      # space_skills tool (9 actions)
│   ├── SpaceToolkitsTool.php    # space_toolkits tool (8 actions)
│   └── SpaceManageTool.php      # space tool (9 actions)
└── SpaceManagerToolkit.php      # ToolkitInterface entry point
```

## License

MIT
