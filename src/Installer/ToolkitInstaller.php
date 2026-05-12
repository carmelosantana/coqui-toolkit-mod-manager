<?php

declare(strict_types=1);

namespace CoquiBot\ModManager\Installer;

use CoquiBot\ModManager\Api\ModClient;
use CoquiBot\ModManager\Config\ModRegistry;

/**
 * Manages the toolkit lifecycle via Composer: install, update, disable, enable, remove.
 *
 * Toolkits are Composer packages installed into the `.workspace/` directory.
 * Disabled toolkits have their version constraint saved in `.mods-state.json`
 * so they can be re-enabled without contacting the API.
 */
final class ToolkitInstaller
{
    public function __construct(
        private readonly ModClient $client,
        private readonly string $workspaceDirectory,
    ) {}

    /**
     * Install a toolkit via Composer.
     *
     * @return array{package: string, message: string}
     */
    public function install(string $package, ?string $version = null): array
    {
        self::validatePackageName($package);

        if (ModRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be managed.");
        }

        $constraint = $version !== null ? "{$package}:{$version}" : $package;

        // Validate version constraint contains only safe characters
        if ($version !== null && !preg_match('/^[a-zA-Z0-9^~><=|.*@ -]+$/', $version)) {
            throw new \InvalidArgumentException(
                "Invalid version constraint: '{$version}'. Contains unsafe characters.",
            );
        }

        $this->runComposer("require {$constraint} --no-interaction");

        // Remove from disabled state if it was previously disabled
        $this->removeDisabledState($package);

        // Log the install (fire-and-forget)
        $this->logInstall($package);

        return [
            'package' => $package,
            'message' => "Toolkit '{$package}' installed. Restart Coqui to activate the new tools.",
        ];
    }

    /**
     * Update a toolkit via Composer.
     *
     * @return array{package: string, message: string}
     */
    public function update(string $package): array
    {
        self::validatePackageName($package);

        if (ModRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be managed.");
        }

        if (!$this->isInstalled($package)) {
            throw new \RuntimeException("Toolkit '{$package}' is not installed.");
        }

        $this->runComposer("update {$package} --no-interaction");

        return [
            'package' => $package,
            'message' => "Toolkit '{$package}' updated. Restart Coqui to load the new version.",
        ];
    }

    /**
     * List installed toolkits (Coqui ecosystem packages only).
     *
     * @return list<array{package: string, constraint: string, status: string}>
     */
    public function list(): array
    {
        $installed = $this->readRequire();
        $disabled = $this->readState();
        $toolkits = [];

        // Active packages from composer.json
        foreach ($installed as $package => $constraint) {
            if (!ModRegistry::looksLikeCoquiPackage($package) || ModRegistry::isExcluded($package)) {
                continue;
            }

            $toolkits[] = [
                'package' => $package,
                'constraint' => $constraint,
                'status' => 'enabled',
            ];
        }

        // Disabled packages from state file
        foreach ($disabled as $package => $info) {
            if (ModRegistry::isExcluded($package)) {
                continue;
            }

            $toolkits[] = [
                'package' => (string) $package,
                'constraint' => (string) ($info['constraint'] ?? '*'),
                'status' => 'disabled',
            ];
        }

        usort($toolkits, static fn(array $a, array $b): int => strcasecmp($a['package'], $b['package']));

        return $toolkits;
    }

    /**
     * Disable a toolkit by removing it from Composer and saving the constraint.
     */
    public function disable(string $package): string
    {
        self::validatePackageName($package);

        if (ModRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be disabled.");
        }

        $installed = $this->readRequire();
        $constraint = $installed[$package] ?? null;

        if ($constraint === null) {
            // Check if already disabled
            $state = $this->readState();
            if (isset($state[$package])) {
                return "Toolkit '{$package}' is already disabled.";
            }
            throw new \RuntimeException("Toolkit '{$package}' is not installed.");
        }

        // Save constraint before removing
        $this->saveDisabledState($package, $constraint);

        $this->runComposer("remove {$package} --no-interaction");

        return "Toolkit '{$package}' disabled. Restart Coqui to apply. Use mods(action: \"enable\", name: \"{$package}\") to re-enable.";
    }

    /**
     * Re-enable a previously disabled toolkit.
     */
    public function enable(string $package): string
    {
        self::validatePackageName($package);

        $state = $this->readState();

        if (!isset($state[$package])) {
            if ($this->isInstalled($package)) {
                return "Toolkit '{$package}' is already enabled.";
            }
            throw new \RuntimeException(
                "Toolkit '{$package}' has no saved state. Use mods_toolkits(action: \"install\", package: \"{$package}\") to install it.",
            );
        }

        $constraint = (string) ($state[$package]['constraint'] ?? '*');

        $this->runComposer("require {$package}:{$constraint} --no-interaction");
        $this->removeDisabledState($package);

        return "Toolkit '{$package}' re-enabled. Restart Coqui to activate.";
    }

    /**
     * Remove a toolkit entirely.
     */
    public function remove(string $package): string
    {
        self::validatePackageName($package);

        if (ModRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be removed.");
        }

        if ($this->isInstalled($package)) {
            $this->runComposer("remove {$package} --no-interaction");
        }

        $this->removeDisabledState($package);

        return "Toolkit '{$package}' removed. Restart Coqui to apply.";
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Validate a Composer package name to prevent shell injection.
     *
     * @throws \InvalidArgumentException If the package name is malformed
     */
    private static function validatePackageName(string $package): void
    {
        if (!preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#i', $package)) {
            throw new \InvalidArgumentException(
                "Invalid package name: '{$package}'. Expected format: vendor/package (alphanumeric, hyphens, dots, underscores).",
            );
        }
    }

    private function isInstalled(string $package): bool
    {
        $require = $this->readRequire();

        return isset($require[$package]);
    }

    /**
     * @return array<string, string>
     */
    private function readRequire(): array
    {
        $composerFile = $this->workspaceDirectory . '/composer.json';

        if (!file_exists($composerFile)) {
            return [];
        }

        $content = file_get_contents($composerFile);
        if ($content === false) {
            return [];
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);

            return is_array($data) ? (array) ($data['require'] ?? []) : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readState(): array
    {
        $stateFile = $this->workspaceDirectory . '/' . ModRegistry::STATE_FILE;

        if (!file_exists($stateFile)) {
            return [];
        }

        $content = file_get_contents($stateFile);
        if ($content === false) {
            return [];
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function saveDisabledState(string $package, string $constraint): void
    {
        $state = $this->readState();
        $state[$package] = [
            'constraint' => $constraint,
            'disabledAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        $this->writeState($state);
    }

    private function removeDisabledState(string $package): void
    {
        $state = $this->readState();
        if (!isset($state[$package])) {
            return;
        }

        unset($state[$package]);
        $this->writeState($state);
    }

    /**
     * @param array<string, array<string, mixed>> $state
     */
    private function writeState(array $state): void
    {
        $stateFile = $this->workspaceDirectory . '/' . ModRegistry::STATE_FILE;

        if ($state === []) {
            if (file_exists($stateFile)) {
                @unlink($stateFile);
            }

            return;
        }

        file_put_contents(
            $stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private function runComposer(string $command): void
    {
        $composer = $this->resolveComposerBinary();
        $fullCommand = sprintf(
            'cd %s && %s %s 2>&1',
            escapeshellarg($this->workspaceDirectory),
            escapeshellarg($composer),
            $command,
        );

        $output = [];
        $exitCode = 0;
        exec($fullCommand, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Composer command failed (exit {$exitCode}): " . implode("\n", $output),
            );
        }
    }

    private function resolveComposerBinary(): string
    {
        $envBin = getenv('COMPOSER_BIN');
        if ($envBin !== false && $envBin !== '') {
            return $envBin;
        }

        // Check common locations
        foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'composer';
    }

    /**
     * Log a toolkit installation for download stats (fire-and-forget).
     */
    private function logInstall(string $package): void
    {
        if (!str_contains($package, '/')) {
            return;
        }

        // Try to resolve owner/name from package name
        // Package format: vendor/name → owner is the vendor, we need to look up the toolkit
        // Best-effort: search for the toolkit to find the correct owner/name on agentcoqui.com
        try {
            $results = $this->client->searchToolkits($package, 1);
            $items = $results['results'] ?? [];

            if ($items === []) {
                return;
            }

            $first = $items[0];
            $owner = ModRegistry::extractOwner($first);
            $urlName = (string) ($first['urlName'] ?? '');

            if ($owner !== '' && $urlName !== '') {
                $this->client->logToolkitInstall($owner, $urlName, 'coqui-toolkit-mod-manager/0.1.0');
            }
        } catch (\Throwable) {
            // Fire-and-forget — don't block install on stats failure
        }
    }
}
