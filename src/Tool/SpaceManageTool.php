<?php

declare(strict_types=1);

namespace CoquiBot\SpaceManager\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Installer\SkillInstaller;
use CoquiBot\SpaceManager\Installer\ToolkitInstaller;

/**
 * Agent-facing tool for managing installed content and social actions.
 *
 * Actions: installed, disable, enable, remove, star, unstar, submit
 */
final class SpaceManageTool implements ToolInterface
{
    public function __construct(
        private readonly SpaceClient $client,
        private readonly SkillInstaller $skillInstaller,
        private readonly ToolkitInstaller $toolkitInstaller,
    ) {}

    public function name(): string
    {
        return 'space';
    }

    public function description(): string
    {
        return 'Manage installed skills/toolkits and interact with Coqui Space. '
            . 'Actions: installed (list all installed content), disable (deactivate without removing), '
            . 'enable (reactivate disabled content), remove (uninstall), '
            . 'star/unstar (community feedback — requires auth), '
            . 'submit (submit a URL for review on coqui.space), '
            . 'tags (discover available tags for filtering), '
            . 'search_all (unified search across skills and toolkits).';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['installed', 'disable', 'enable', 'remove', 'star', 'unstar', 'submit', 'tags', 'search_all'],
            ),
            new StringParameter('name', 'Content identifier: skill directory name for skills, vendor/package for toolkits. Auto-detected by "/" presence.', required: false),
            new EnumParameter('type', 'Content type filter (for installed/tags)', ['all', 'skills', 'toolkits', 'skill', 'toolkit'], required: false),
            new EnumParameter('entity_type', 'Entity type for star/unstar', ['skill', 'toolkit'], required: false),
            new StringParameter('owner', 'GitHub username (required for star/unstar)', required: false),
            new StringParameter('source_url', 'Repository or source URL (required for submit)', required: false),
            new StringParameter('notes', 'Additional notes for submission', required: false),
            new BoolParameter('purge', 'Permanently delete when removing (default: false — just disables)', required: false),
            new StringParameter('query', 'Search keywords (required for search_all)', required: false),
            new NumberParameter('limit', 'Maximum results for search_all (1-50, default 10)', required: false),
            new StringParameter('cursor', 'Pagination cursor for search_all (applies to skill results only)', required: false),
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
                'star' => $this->star($input),
                'unstar' => $this->unstar($input),
                'submit' => $this->submit($input),
                'tags' => $this->tags($input),
                'search_all' => $this->searchAll($input),
                default => ToolResult::error("Unknown action: '{$action}'. Valid actions: installed, disable, enable, remove, star, unstar, submit, tags, search_all"),
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
                    $origin = $skill['source'] === 'coqui.space'
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
            $lines[] = 'No skills or toolkits installed. Use `space_skills(action: "search", query: "...")` or `space_toolkits(action: "search", query: "...")` to discover content.';
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
    private function star(array $input): ToolResult
    {
        $entityType = (string) ($input['entity_type'] ?? '');
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($entityType === '' || $owner === '' || $name === '') {
            return ToolResult::error('Parameters "entity_type", "owner", and "name" are required for star.');
        }

        $result = $this->client->star($entityType, $owner, $name);

        $alreadyStarred = !empty($result['alreadyStarred']);

        return ToolResult::success(
            $alreadyStarred
                ? "You already starred {$entityType} `{$owner}/{$name}`."
                : "Starred {$entityType} `{$owner}/{$name}` ★",
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function unstar(array $input): ToolResult
    {
        $entityType = (string) ($input['entity_type'] ?? '');
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($entityType === '' || $owner === '' || $name === '') {
            return ToolResult::error('Parameters "entity_type", "owner", and "name" are required for unstar.');
        }

        $result = $this->client->unstar($entityType, $owner, $name);

        $alreadyUnstarred = !empty($result['alreadyUnstarred']);

        return ToolResult::success(
            $alreadyUnstarred
                ? "{$entityType} `{$owner}/{$name}` was not starred."
                : "Unstarred {$entityType} `{$owner}/{$name}`.",
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function submit(array $input): ToolResult
    {
        $type = (string) ($input['type'] ?? '');
        $sourceUrl = (string) ($input['source_url'] ?? '');
        $notes = isset($input['notes']) ? (string) $input['notes'] : null;

        if ($type === '' || !in_array($type, ['skill', 'toolkit'], true)) {
            return ToolResult::error('Parameter "type" is required for submit (must be "skill" or "toolkit").');
        }

        if ($sourceUrl === '') {
            return ToolResult::error('Parameter "source_url" is required for submit.');
        }

        $result = $this->client->createSubmission($type, $sourceUrl, $notes);
        $id = $result['id'] ?? 'unknown';

        return ToolResult::success(
            "Submission created (#" . (string) $id . "). "
            . "A moderator will review your {$type} at `{$sourceUrl}`. "
            . 'You can track the status from your dashboard on coqui.space.',
        );
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
        $lines[] = '*Use tags to filter results: `space_skills(action: "list", tags: "tag-slug")` or `space_toolkits(action: "list", tags: "tag-slug")`*';

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
                $downloads = (int) ($item['downloads'] ?? 0);
                $favers = (int) ($item['favers'] ?? 0);
                $verified = !empty($item['verified_publisher']) ? '✓' : '—';

                $lines[] = "| `{$name}` | {$downloads} | {$favers} | {$verified} |";
            }
        }

        $lines[] = '';
        $lines[] = '*Use entity-specific tools for more details and pagination.*';

        return ToolResult::success(implode("\n", $lines));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Determine if a name refers to a toolkit (contains /) or a skill.
     */
    private function isToolkit(string $name): bool
    {
        return str_contains($name, '/');
    }
}
