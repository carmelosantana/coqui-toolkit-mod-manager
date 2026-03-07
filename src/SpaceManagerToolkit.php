<?php

declare(strict_types=1);

namespace CoquiBot\SpaceManager;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Config\SpaceRegistry;
use CoquiBot\SpaceManager\Installer\SkillInstaller;
use CoquiBot\SpaceManager\Installer\ToolkitInstaller;
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
        return [
            new SpaceSkillsTool($this->client, $this->skillInstaller),
            new SpaceToolkitsTool($this->client, $this->toolkitInstaller),
            new SpaceManageTool($this->client, $this->skillInstaller, $this->toolkitInstaller),
        ];
    }

    public function guidelines(): string
    {
        $authenticated = ($this->tokenResolver)() !== '';
        $authStatus = $authenticated ? 'authenticated' : 'anonymous (limited functionality)';

        return <<<GUIDELINES
        <space_manager>
        ## Coqui Space Manager

        API Status: {$authStatus}

        ### Three tools — use the right one

        | Tool | Purpose | Key actions |
        |------|---------|-------------|
        | `space_skills` | Browse and install skills | search, list, details, versions, reviews, file, install, update, publish |
        | `space_toolkits` | Browse and install toolkits | search, popular, details, reviews, install, update, publish |
        | `space` | Manage installed content & social actions | installed, disable, enable, remove, star, unstar, submit |

        ### Identifier patterns

        - **Skills** are identified by `owner/name` (e.g. `carmelosantana/code-review`). Directory name after install is the skill name.
        - **Toolkits** are Composer packages identified by `vendor/package` (e.g. `coquibot/coqui-toolkit-brave-search`).
        - Any identifier with a `/` is treated as a toolkit in the `space` tool. Skill names without `/` are matched by directory name.

        ### Authentication required for

        - `star`, `unstar`, `submit`
        - `publish` (both skills and toolkits)
        - `me` (profile info)

        Anonymous access supports: search, details, list, versions, reviews, install, update.

        ### Workflow patterns

        **Discover → Install:**
        1. `space_skills(action: "search", query: "code review")` or `space_toolkits(action: "search", query: "brave")`
        2. `space_skills(action: "details", owner: "carmelosantana", name: "code-review")` for full info
        3. `space_skills(action: "install", owner: "carmelosantana", name: "code-review")` to install

        **Manage installed:**
        1. `space(action: "installed")` — see everything installed
        2. `space(action: "disable", name: "code-review")` — deactivate a skill
        3. `space(action: "enable", name: "code-review")` — reactivate it
        4. `space(action: "remove", name: "code-review", purge: true)` — fully remove

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
