<?php

declare(strict_types=1);

namespace CoquiBot\SpaceManager;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Config\SpaceRegistry;
use CoquiBot\SpaceManager\Installer\SkillInstaller;
use CoquiBot\SpaceManager\Installer\ToolkitInstaller;
use CoquiBot\SpaceManager\Tool\SpaceAccountTool;
use CoquiBot\SpaceManager\Tool\SpaceManageTool;
use CoquiBot\SpaceManager\Tool\SpaceSkillsTool;
use CoquiBot\SpaceManager\Tool\SpaceToolkitsTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Primary toolkit entry point for Coqui Space integration.
 *
 * Provides three tools:
 * - `space_skills`   — search, browse, install, update, and publish skills
 * - `space_toolkits` — search, browse, install, update, and publish toolkits
 * - `space`          — manage installed content, star/unstar, submit for review
 *
 * Auto-discovered via `extra.php-agents.toolkits` in composer.json.
 */
final class SpaceManagerToolkit implements ToolkitInterface
{
    /** @var \Closure(): string */
    private readonly \Closure $tokenResolver;

    private readonly SpaceClient $client;

    private readonly SkillInstaller $skillInstaller;

    private readonly ToolkitInstaller $toolkitInstaller;

    /**
     * @param \Closure(): string          $urlResolver   Returns API base URL
     * @param \Closure(): string          $tokenResolver Returns bearer token (empty = anonymous)
     * @param string                      $workspaceDir  Workspace directory (.workspace/)
     * @param HttpClientInterface|null    $http          Injected HTTP client (defaults to Symfony HttpClient)
     */
    public function __construct(
        \Closure $urlResolver,
        \Closure $tokenResolver,
        string $workspaceDir,
        ?HttpClientInterface $http = null,
    ) {
        $this->tokenResolver = $tokenResolver;

        $http ??= HttpClient::create();
        $this->client = new SpaceClient($urlResolver, $tokenResolver, $http);

        $skillsDir = rtrim($workspaceDir, '/') . '/skills';
        if (!is_dir($skillsDir)) {
            mkdir($skillsDir, 0o755, true);
        }

        $this->skillInstaller = new SkillInstaller($this->client, $skillsDir);
        $this->toolkitInstaller = new ToolkitInstaller($this->client, $workspaceDir);
    }

    /**
     * Convenient factory for environment-based construction.
     *
     * Reads COQUI_SPACE_URL and COQUI_SPACE_API_TOKEN from env on every call
     * to support hot-reload via CredentialTool → putenv().
     */
    public static function fromEnv(?string $workspaceDir = null): self
    {
        $workspaceDir ??= self::resolveWorkspaceDir();

        $urlResolver = static function (): string {
            $env = getenv('COQUI_SPACE_URL');
            return $env !== false && $env !== '' ? rtrim($env, '/') : SpaceRegistry::DEFAULT_BASE_URL;
        };

        $tokenResolver = static function (): string {
            $env = getenv('COQUI_SPACE_API_TOKEN');
            return $env !== false ? $env : '';
        };

        return new self($urlResolver, $tokenResolver, $workspaceDir);
    }

    /**
     * @return list<\CarmeloSantana\PHPAgents\Contract\ToolInterface>
     */
    public function tools(): array
    {
        $tools = [
            new SpaceSkillsTool($this->client, $this->skillInstaller),
            new SpaceToolkitsTool($this->client, $this->toolkitInstaller),
            new SpaceManageTool($this->client, $this->skillInstaller, $this->toolkitInstaller),
        ];

        if (($this->tokenResolver)() !== '') {
            $tools[] = new SpaceAccountTool($this->client);
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $authenticated = ($this->tokenResolver)() !== '';
        $authStatus = $authenticated ? 'authenticated' : 'anonymous (limited functionality)';

        $accountRow = $authenticated
            ? "\n        | `space_account` | Your account dashboard | profile, my_skills, my_toolkits, my_collections, my_submissions, my_installs, my_analytics, my_stars |"
            : '';

        return <<<GUIDELINES
        <space_manager>
        ## Coqui Space Manager

        API Status: {$authStatus}

        ### Tools — use the right one

        | Tool | Purpose | Key actions |
        |------|---------|-------------|
        | `space_skills` | Browse, install and manage skills | search, list, details, versions, reviews, file, install, update, publish, delete, log_install |
        | `space_toolkits` | Browse, install and manage toolkits | search, list, popular, details, reviews, install, update, publish, delete |
        | `space` | Manage content, collections, reviews, notifications, tags, unified search | installed, disable, enable, remove, star, unstar, submit, tags, search_all, collections, review, notifications, health |{$accountRow}

        ### Identifier patterns

        - **Skills** are identified by `owner/name` (e.g. `carmelosantana/code-review`). Directory name after install is the skill name.
        - **Toolkits** are Composer packages identified by `vendor/package` (e.g. `coquibot/coqui-toolkit-brave-search`).
        - Any identifier with a `/` is treated as a toolkit in the `space` tool. Skill names without `/` are matched by directory name.

        ### Authentication required for

        - `star`, `unstar`, `submit`, `delete`, `publish`, `review`
        - `collections` (create/update/delete/add_item/remove_item)
        - `notifications`, `space_account` (all actions)
        - Anonymous access: search, details, list, versions, reviews, install, update, tags, search_all, health

        ### Workflow patterns

        **Discover → Install:**
        1. `space_skills(action: "search", query: "code review")`
        2. `space_skills(action: "details", owner: "carmelosantana", name: "code-review")`
        3. `space_skills(action: "install", owner: "carmelosantana", name: "code-review")`

        **Collections:**
        1. `space(action: "collections", sub_action: "create", collection_name: "My Favorites", description: "...", is_public: true)`
        2. `space(action: "collections", sub_action: "add_item", collection_id: "abc", entity_type: "skill", owner: "carmelosantana", name: "code-review")`

        **Reviews:**
        1. `space(action: "review", entity_type: "skill", owner: "carmelosantana", name: "code-review", rating: 5, title: "Great!", body: "Works perfectly.")`

        **Manage installed:**
        1. `space(action: "installed")` — see everything installed
        2. `space(action: "disable", name: "code-review")` — deactivate
        3. `space(action: "remove", name: "code-review", purge: true)` — fully remove

        **Social:**
        1. `space(action: "star", entity_type: "skill", owner: "carmelosantana", name: "code-review")`
        2. `space(action: "submit", type: "toolkit", source_url: "https://github.com/user/repo")`

        ### Verified publishers

        Some skills and toolkits are from verified publishers (indicated by a ✓ badge). Prefer verified content when alternatives exist.
        </space_manager>
        GUIDELINES;
    }

    /**
     * Resolve the workspace directory in priority order:
     * 1. .workspace/ in cwd (dev mode)
     * 2. ~/.workspace/ (default)
     */
    private static function resolveWorkspaceDir(): string
    {
        $local = getcwd() . '/.workspace';
        if (is_dir($local)) {
            return $local;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        return $home . '/.workspace';
    }
}
