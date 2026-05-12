<?php

declare(strict_types=1);

namespace CoquiBot\ModManager\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\ModManager\Api\ModClient;
use CoquiBot\ModManager\Installer\SkillInstaller;
use CoquiBot\ModManager\Installer\ToolkitInstaller;

/**
 * Agent-facing tool for managing installed content and discovery actions.
 *
 * Actions: installed, disable, enable, remove, tags, search_all, health
 */
final class ModsManageTool implements ToolInterface
{
    public function __construct(
        private readonly ModClient $client,
        private readonly SkillInstaller $skillInstaller,
        private readonly ToolkitInstaller $toolkitInstaller,
    ) {}

    public function name(): string
    {
        return 'mods';
    }

    public function description(): string
    {
        return 'Manage installed skills/toolkits and discover content from Coqui Mods. '
            . 'Actions: installed (list all installed content), disable (deactivate without removing), '
            . 'enable (reactivate disabled content), remove (uninstall), '
            . 'tags (discover available tags for filtering), '
            . 'search_all (unified search across skills and toolkits), '
            . 'health (check API status).';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['installed', 'disable', 'enable', 'remove', 'tags', 'search_all', 'health'],
            ),
            new StringParameter('name', 'Content identifier: skill directory name for skills, vendor/package for toolkits. Auto-detected by "/" presence.', required: false),
            new EnumParameter('type', 'Content type filter (for installed/tags)', ['all', 'skills', 'toolkits', 'skill', 'toolkit'], required: false),
            new BoolParameter('purge', 'Permanently delete when removing (default: false — just disables)', required: false),
            new StringParameter('query', 'Search keywords (required for search_all)', required: false),
            new NumberParameter('limit', 'Maximum results (1-50)', required: false),
            new StringParameter('cursor', 'Pagination cursor', required: false),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        try {
            return match ($action) {
                'installed' => $this->installed($input),
                'disable' => $this->disable($input),
                'enable' => $this->enable($input),
                'remove' => $this->remove($input),
                'tags' => $this->tags($input),
                'search_all' => $this->searchAll($input),
                'health' => $this->health(),
                default => ToolResult::error("Unknown action: '{$action}'. Valid: installed, disable, enable, remove, tags, search_all, health"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toFunctionSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters() as $param) {
            $properties[$param->name] = $param->toSchema();
            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    // ── Actions ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function installed(array $input): ToolResult
    {
        $type = (string) ($input['type'] ?? 'all');

        $lines = ['## Installed Content'];
        $hasContent = false;

        // Skills
        if ($type === 'all' || $type === 'skills' || $type === 'skill') {
            $skills = $this->skillInstaller->list();
            $lines[] = '';
            $lines[] = '### Skills';

            if ($skills === []) {
                $lines[] = 'No skills installed.';
            } else {
                $lines[] = '';
                $lines[] = '| Name | Version | Status | Source | Origin |';
                $lines[] = '|------|---------|--------|--------|--------|';

                foreach ($skills as $skill) {
                    $origin = $skill['source'] === 'coqui.mods'
                        ? "`{$skill['owner']}/{$skill['slug']}`"
                        : 'local';
                    $statusIcon = $skill['status'] === 'enabled' ? '✓' : '○';

                    $lines[] = "| {$skill['name']} | {$skill['version']} | {$statusIcon} {$skill['status']} | {$skill['source']} | {$origin} |";
                }

                $hasContent = true;
            }
        }

        // Toolkits
        if ($type === 'all' || $type === 'toolkits' || $type === 'toolkit') {
            $toolkits = $this->toolkitInstaller->list();
            $lines[] = '';
            $lines[] = '### Toolkits';

            if ($toolkits === []) {
                $lines[] = 'No Coqui toolkits installed.';
            } else {
                $lines[] = '';
                $lines[] = '| Package | Constraint | Status |';
                $lines[] = '|---------|------------|--------|';

                foreach ($toolkits as $toolkit) {
                    $statusIcon = $toolkit['status'] === 'enabled' ? '✓' : '○';
                    $lines[] = "| `{$toolkit['package']}` | {$toolkit['constraint']} | {$statusIcon} {$toolkit['status']} |";
                }

                $hasContent = true;
            }
        }

        if (!$hasContent && $type === 'all') {
            $lines[] = '';
            $lines[] = 'No skills or toolkits installed. Use `mods_skills(action: "search", query: "...")` or `mods_toolkits(action: "search", query: "...")` to discover content.';
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function disable(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for disable.');
        }

        if ($this->isToolkit($name)) {
            $message = $this->toolkitInstaller->disable($name);
        } else {
            $message = $this->skillInstaller->disable($name);
        }

        return ToolResult::success($message);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function enable(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for enable.');
        }

        if ($this->isToolkit($name)) {
            $message = $this->toolkitInstaller->enable($name);
        } else {
            $message = $this->skillInstaller->enable($name);
        }

        return ToolResult::success($message);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function remove(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for remove.');
        }

        $purge = (bool) ($input['purge'] ?? false);

        if ($this->isToolkit($name)) {
            $message = $this->toolkitInstaller->remove($name);
        } else {
            $message = $this->skillInstaller->remove($name, $purge);
        }

        return ToolResult::success($message);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function tags(array $input): ToolResult
    {
        $type = (string) ($input['type'] ?? 'all');

        // Normalize type to the API expected values
        $apiType = match ($type) {
            'skill', 'skills' => 'skills',
            'toolkit', 'toolkits' => 'toolkits',
            default => 'all',
        };

        $data = $this->client->getTags($apiType);

        $skillTags = (array) ($data['skills'] ?? []);
        $toolkitTags = (array) ($data['toolkits'] ?? []);

        $lines = ['## Available Tags'];

        if ($apiType === 'all' || $apiType === 'skills') {
            $lines[] = '';
            $lines[] = '### Skill Tags';
            if ($skillTags === []) {
                $lines[] = 'No skill tags available.';
            } else {
                $slugs = array_map(static fn(array $tag): string => (string) ($tag['slug'] ?? $tag['name'] ?? ''), $skillTags);
                $lines[] = implode(', ', array_filter($slugs));
            }
        }

        if ($apiType === 'all' || $apiType === 'toolkits') {
            $lines[] = '';
            $lines[] = '### Toolkit Tags';
            if ($toolkitTags === []) {
                $lines[] = 'No toolkit tags available.';
            } else {
                $slugs = array_map(static fn(array $tag): string => (string) ($tag['slug'] ?? $tag['name'] ?? ''), $toolkitTags);
                $lines[] = implode(', ', array_filter($slugs));
            }
        }

        $lines[] = '';
        $lines[] = '*Use tags to filter results: `mods_skills(action: "list", tags: "tag-slug")` or `mods_toolkits(action: "list", tags: "tag-slug")`*';

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function searchAll(array $input): ToolResult
    {
        $query = (string) ($input['query'] ?? '');
        if ($query === '') {
            return ToolResult::error('Parameter "query" is required for search_all.');
        }

        $limit = (int) ($input['limit'] ?? 10);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->searchAll($query, $limit, $cursor);

        $skillResults = (array) ($data['skills']['results'] ?? []);
        $toolkitResults = (array) ($data['toolkits']['results'] ?? []);
        $toolkitTotal = (int) ($data['toolkits']['total'] ?? count($toolkitResults));

        if ($skillResults === [] && $toolkitResults === []) {
            return ToolResult::success("No results found for \"{$query}\".");
        }

        $lines = ["## Search results for \"{$query}\"\n"];

        // Skills section
        $lines[] = '### Skills';
        if ($skillResults === []) {
            $lines[] = 'No matching skills.';
        } else {
            $lines[] = '';
            $lines[] = '| Skill | Owner | Version | Verified |';
            $lines[] = '|-------|-------|---------|----------|';

            foreach ($skillResults as $item) {
                $name = (string) ($item['name'] ?? '');
                $displayName = (string) ($item['displayName'] ?? $name);
                $owner = (string) ($item['owner'] ?? '');
                $version = (string) ($item['version'] ?? '-');
                $verified = !empty($item['verified_publisher']) ? '✓' : '—';

                $lines[] = "| {$displayName} (`{$owner}/{$name}`) | {$owner} | {$version} | {$verified} |";
            }
        }

        // Toolkits section
        $lines[] = '';
        $lines[] = "### Toolkits ({$toolkitTotal} total)";
        if ($toolkitResults === []) {
            $lines[] = 'No matching toolkits.';
        } else {
            $lines[] = '';
            $lines[] = '| Package | Downloads | Favers | Verified |';
            $lines[] = '|---------|-----------|--------|----------|';

            foreach ($toolkitResults as $item) {
                $name = (string) ($item['name'] ?? '');
                $downloads = $this->formatNumber((int) ($item['downloads'] ?? 0));
                $favers = $this->formatNumber((int) ($item['favers'] ?? 0));
                $verified = !empty($item['verified_publisher']) ? '✓' : '—';

                $lines[] = "| `{$name}` | {$downloads} | {$favers} | {$verified} |";
            }
        }

        $lines[] = '';
        $lines[] = '*Use entity-specific tools for more details and pagination.*';

        return ToolResult::success(implode("\n", $lines));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function health(): ToolResult
    {
        $data = $this->client->healthCheck();
        $status = (string) ($data['status'] ?? 'unknown');
        $version = (string) ($data['version'] ?? '-');
        $timestamp = (string) ($data['timestamp'] ?? '-');

        return ToolResult::success("API Status: {$status} | Version: {$version} | Time: {$timestamp}");
    }

    // ── Content helpers ──────────────────────────────────────────────

    private function formatNumber(int $value): string
    {
        if ($value >= 1_000_000) {
            return number_format($value / 1_000_000, 1) . 'M';
        }
        if ($value >= 1_000) {
            return number_format($value / 1_000, 1) . 'K';
        }
        return (string) $value;
    }

    /**
     * Determine if a name refers to a toolkit (contains /) or a skill.
     */
    private function isToolkit(string $name): bool
    {
        return str_contains($name, '/');
    }
}
