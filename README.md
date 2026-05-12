# Coqui Mod Manager

Coqui Mods manager toolkit — browse, install, update, and manage skills and toolkits from [agentcoqui.com](https://agentcoqui.com).

## Installation

```bash
composer require coquibot/coqui-toolkit-mod-manager
```

The toolkit is auto-discovered by Coqui via `extra.php-agents.toolkits` in `composer.json`. No additional configuration needed.

## Credentials

| Key | Required | Description |
| --- | --- | --- |
| `COQUI_MODS_API_TOKEN` | Optional | API token from agentcoqui.com — enables authenticated read context here and is reused by the mod-publish toolkit for write actions |
| `COQUI_MODS_URL` | Optional | Custom API base URL (default: `https://agentcoqui.com/api/v1`) |

Set credentials via the Coqui `credentials` tool:

```text
credentials(action: "set", key: "COQUI_MODS_API_TOKEN", value: "cqs_...")
```

Most read operations work without authentication. Publish, delete, account, collections, reviews, notifications, and other social write actions now live in `coquibot/coqui-toolkit-mod-publish`.

## Tools

The toolkit provides three tools plus the self-registering `/mods` REPL command.

### `mods_skills` — Skill Discovery & Installation

| Action | Parameters | Description |
| --- | --- | --- |
| `search` | `query`, `limit?`, `cursor?` | Search skills by keyword |
| `list` | `limit?`, `cursor?`, `sort?`, `tag?` | Browse all skills with filters |
| `details` | `owner`, `name` | Full skill information |
| `versions` | `owner`, `name` | Version history |
| `reviews` | `owner`, `name` | Community reviews |
| `file` | `owner`, `name`, `path` | View a specific file from a skill |
| `install` | `owner`, `name`, `version?`, `force?` | Download and install a skill |
| `update` | `name` | Update an installed skill to latest |
| `log_install` | `owner`, `name` | Log an install event for analytics |

### `mods_toolkits` — Toolkit Discovery & Installation

| Action | Parameters | Description |
| --- | --- | --- |
| `search` | `query`, `limit?`, `page?` | Search toolkits by keyword |
| `list` | `limit?`, `cursor?`, `sort?`, `tags?` | Browse all toolkits with cursor pagination |
| `popular` | `limit?`, `page?` | Browse popular toolkits |
| `details` | `owner`, `name` or `package` | Full toolkit information |
| `reviews` | `owner`, `name` | Community reviews |
| `install` | `package`, `version?` | Install via Composer |
| `update` | `package` | Update via Composer |

### `mods` — Management & Discovery

| Action | Parameters | Description |
| --- | --- | --- |
| `installed` | `type?` (all/skills/toolkits) | List all installed content |
| `disable` | `name` | Deactivate without removing |
| `enable` | `name` | Reactivate disabled content |
| `remove` | `name`, `purge?` | Uninstall (purge=true deletes permanently) |
| `tags` | `type?` (all/skills/toolkits) | Browse available tags |
| `search_all` | `query`, `limit?`, `cursor?` | Unified search across skills and toolkits |
| `health` | — | Check API health status |

**Identifier convention:** Names containing `/` are treated as toolkits (Composer packages). Names without `/` are treated as skills (directory names).

### `/mods` — REPL Command

The package also owns the external `/mods` slash command in Coqui's REPL.

Supported flows:

- `/mods search <query>`
- `/mods install <owner/name|vendor/package>`
- `/mods remove <identifier>`
- `/mods installed`
- `/mods update <identifier>`

## Standalone Usage

```php
<?php

use CoquiBot\ModManager\ModManagerToolkit;

// From environment variables
$toolkit = ModManagerToolkit::fromEnv();

// Or with explicit configuration
$toolkit = new ModManagerToolkit(
    urlResolver: static fn() => 'https://agentcoqui.com/api/v1',
    tokenResolver: static fn() => getenv('COQUI_MODS_API_TOKEN') ?: '',
    workspaceDir: '/path/to/.workspace',
);

// Get tools
foreach ($toolkit->tools() as $tool) {
    echo $tool->name() . ': ' . $tool->description() . "\n";
}

// Execute a tool directly
$tool = $toolkit->tools()[0]; // mods_skills
$result = $tool->execute(['action' => 'search', 'query' => 'code review']);
echo $result->content;
```

## Gated Operations

The following operations require user confirmation (unless `--auto-approve` is enabled):

- `mods_skills`: install, update
- `mods_toolkits`: install, update
- `mods`: disable, enable, remove

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

```text
src/
├── Api/
│   └── ModClient.php            # HTTP client for all API endpoints
├── Config/
│   └── ModRegistry.php          # Constants, excluded packages, helpers
├── Installer/
│   ├── SkillInstaller.php       # Skill lifecycle (ZIP download/extract)
│   └── ToolkitInstaller.php     # Toolkit lifecycle (Composer-based)
├── Tool/
│   ├── ModsSkillsTool.php       # mods_skills tool
│   ├── ModsToolkitsTool.php     # mods_toolkits tool
│   └── ModsManageTool.php       # mods tool
├── Command/
│   └── ModsCommandHandler.php   # /mods REPL command handler
└── ModManagerToolkit.php        # ToolkitInterface entry point
```

## License

MIT
