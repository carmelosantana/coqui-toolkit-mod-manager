<?php

declare(strict_types=1);

namespace CoquiBot\ModManager;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Contract\ReplCommandProvider;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\ModManager\Command\ModsCommandHandler;
use CoquiBot\ModManager\Api\ModClient;
use CoquiBot\ModManager\Config\ModRegistry;
use CoquiBot\ModManager\Installer\SkillInstaller;
use CoquiBot\ModManager\Installer\ToolkitInstaller;
use CoquiBot\ModManager\Tool\ModsManageTool;
use CoquiBot\ModManager\Tool\ModsSkillsTool;
use CoquiBot\ModManager\Tool\ModsToolkitsTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Primary toolkit entry point for Coqui Mods integration.
 *
 * Provides three tools:
 * - `mods_skills`   — search, browse, install, and update skills
 * - `mods_toolkits` — search, browse, install, and update toolkits
 * - `mods`          — manage installed content, tags, and unified search
 *
 * Auto-discovered via `extra.php-agents.toolkits` in composer.json.
 */
final class ModManagerToolkit implements ToolkitInterface, ReplCommandProvider
{
    /** @var \Closure(): string */
    private readonly \Closure $tokenResolver;

    private readonly ModClient $client;

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
        $this->client = new ModClient($urlResolver, $tokenResolver, $http);

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
    * Reads COQUI_MODS_URL and COQUI_MODS_API_TOKEN from env on every call
     * to support hot-reload via CredentialTool → putenv().
     */
    public static function fromEnv(?string $workspaceDir = null): self
    {
        $workspaceDir ??= self::resolveWorkspaceDir();

        $urlResolver = static function (): string {
            $env = getenv('COQUI_MODS_URL');
            return $env !== false && $env !== '' ? rtrim($env, '/') : ModRegistry::DEFAULT_BASE_URL;
        };

        $tokenResolver = static function (): string {
            $env = getenv('COQUI_MODS_API_TOKEN');
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
            new ModsSkillsTool($this->client, $this->skillInstaller),
            new ModsToolkitsTool($this->client, $this->toolkitInstaller),
            new ModsManageTool($this->client, $this->skillInstaller, $this->toolkitInstaller),
        ];

        return $tools;
    }

    public function guidelines(): string
    {
        $authStatus = ($this->tokenResolver)() !== '' ? 'authenticated' : 'anonymous (limited functionality)';

        return <<<GUIDELINES
        <mod_manager>
        ## Coqui Mods Manager

        API Status: {$authStatus}

        ### Tools — use the right one

        | Tool | Purpose | Key actions |
        |------|---------|-------------|
        | `mods_skills` | Browse, install and manage skills | search, list, details, versions, reviews, file, install, update, log_install |
        | `mods_toolkits` | Browse, install and manage toolkits | search, list, popular, details, reviews, install, update |
        | `mods` | Manage installed content, tags, and unified search | installed, disable, enable, remove, tags, search_all, health |

        ### Identifier patterns

        - **Skills** are identified by `owner/name` (e.g. `carmelosantana/code-review`). Directory name after install is the skill name.
        - **Toolkits** are Composer packages identified by `vendor/package` (e.g. `coquibot/coqui-toolkit-brave-search`).
        - Any identifier with a `/` is treated as a toolkit in the `mods` tool. Skill names without `/` are matched by directory name.

        ### Authentication required for

        - Publish, delete, collections, notifications, and account actions now live in the mod-publish toolkit
        - Anonymous access here covers search, details, list, versions, reviews, install, update, tags, search_all, and health

        ### Workflow patterns

        **Discover → Install:**
        1. `mods_skills(action: "search", query: "code review")`
        2. `mods_skills(action: "details", owner: "carmelosantana", name: "code-review")`
        3. `mods_skills(action: "install", owner: "carmelosantana", name: "code-review")`

        **Manage installed:**
        1. `mods(action: "installed")` — see everything installed
        2. `mods(action: "disable", name: "code-review")` — deactivate
        3. `mods(action: "remove", name: "code-review", purge: true)` — fully remove

        ### Verified publishers

        Some skills and toolkits are from verified publishers (indicated by a ✓ badge). Prefer verified content when alternatives exist.
        </mod_manager>
        GUIDELINES;
    }

    /**
     * @return list<ToolkitCommandHandler>
     */
    public function commandHandlers(): array
    {
        return [
            new ModsCommandHandler(
                $this->client,
                $this->skillInstaller,
                $this->toolkitInstaller,
                ($this->tokenResolver)() !== '',
            ),
        ];
    }

    public function client(): ModClient
    {
        return $this->client;
    }

    public function skillInstaller(): SkillInstaller
    {
        return $this->skillInstaller;
    }

    public function toolkitInstaller(): ToolkitInstaller
    {
        return $this->toolkitInstaller;
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
