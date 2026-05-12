<?php

declare(strict_types=1);

namespace CoquiBot\ModManager\Command;

use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Contract\ToolkitReplContext;
use CoquiBot\ModManager\Api\ModClient;
use CoquiBot\ModManager\Installer\SkillInstaller;
use CoquiBot\ModManager\Installer\ToolkitInstaller;

/**
 * Self-registering REPL command handler for the external /mods surface.
 */
final class ModsCommandHandler implements ToolkitCommandHandler
{
    public function __construct(
        private readonly ModClient $client,
        private readonly SkillInstaller $skillInstaller,
        private readonly ToolkitInstaller $toolkitInstaller,
        private readonly bool $authenticated,
    ) {}

    public function commandName(): string
    {
        return 'mods';
    }

    /**
     * @return list<string>
     */
    public function subcommands(): array
    {
        return ['status', 'search', 'install', 'remove', 'installed', 'skills', 'toolkits', 'update'];
    }

    public function usage(): string
    {
        return '/mods [action]';
    }

    public function description(): string
    {
        return 'Browse, install, update, and remove mods from agentcoqui.com.';
    }

    public function handle(ToolkitReplContext $context, string $arg): void
    {
        $parts = explode(' ', trim($arg), 2);
        $action = strtolower($parts[0] ?? '');
        $target = trim($parts[1] ?? '');

        if ($action === '' || $action === 'status') {
            $this->showStatus($context);
            return;
        }

        if ($action === 'search') {
            $this->search($context, $target);
            return;
        }

        if ($action === 'install') {
            $this->install($context, $target);
            return;
        }

        if ($action === 'remove') {
            $this->remove($context, $target);
            return;
        }

        if ($action === 'skills') {
            $this->listSkills($context);
            return;
        }

        if ($action === 'toolkits') {
            $this->listToolkits($context);
            return;
        }

        if ($action === 'installed') {
            $this->listInstalled($context);
            return;
        }

        if ($action === 'update') {
            $this->update($context, $target);
            return;
        }

        $context->io->error('Unknown /mods subcommand: ' . $action . '. Use: search, install, remove, installed, skills, toolkits, update');
    }

    private function showStatus(ToolkitReplContext $context): void
    {
        try {
            $health = $this->client->healthCheck();
            $status = ($health['status'] ?? 'unknown') === 'ok' ? '<fg=green>connected</>' : '<fg=red>unreachable</>';
        } catch (\Throwable) {
            $status = '<fg=red>unreachable</>';
        }

        $authenticated = $this->authenticated ? '<fg=green>yes</>' : '<fg=yellow>no (set COQUI_MODS_API_TOKEN)</>';
        $installedSkills = $this->skillInstaller->list();
        $installedToolkits = $this->toolkitInstaller->list();

        $context->io->text([
            '<fg=cyan>Coqui Mods</>',
            '  API: ' . $status,
            '  Authenticated: ' . $authenticated,
            '  Installed skills: ' . count($installedSkills),
            '  Installed toolkits: ' . count($installedToolkits),
            '',
            '<fg=gray>Commands: /mods search|install|remove|installed|update</>',
        ]);
    }

    private function search(ToolkitReplContext $context, string $target): void
    {
        if ($target === '') {
            $context->io->error('Usage: /mods search <query>');
            return;
        }

        try {
            $results = $this->client->searchAll($target);
            $rows = [];

            foreach ((array) ($results['skills']['results'] ?? []) as $skill) {
                if (!is_array($skill)) {
                    continue;
                }

                $owner = (string) ($skill['owner'] ?? '');
                $name = (string) ($skill['urlName'] ?? $skill['name'] ?? '');
                $desc = mb_substr((string) ($skill['description'] ?? $skill['shortDescription'] ?? ''), 0, 60);
                $rows[] = ['skill', $owner . '/' . $name, $desc];
            }

            foreach ((array) ($results['toolkits']['results'] ?? []) as $toolkit) {
                if (!is_array($toolkit)) {
                    continue;
                }

                $pkg = (string) ($toolkit['name'] ?? '');
                $desc = mb_substr((string) ($toolkit['description'] ?? $toolkit['shortDescription'] ?? ''), 0, 60);
                $rows[] = ['toolkit', $pkg, $desc];
            }

            if ($rows === []) {
                $context->io->text('No results found for "' . $target . '".');
                return;
            }

            $context->io->table(['Type', 'Identifier', 'Description'], $rows);
        } catch (\Throwable $e) {
            $context->io->error('Search failed: ' . $e->getMessage());
        }
    }

    private function install(ToolkitReplContext $context, string $target): void
    {
        if ($target === '') {
            $context->io->error('Usage: /mods install <owner/name>');
            return;
        }

        if (!str_contains($target, '/')) {
            $context->io->error('Invalid identifier. Use owner/name for skills or vendor/package for toolkits.');
            return;
        }

        try {
            [$firstPart, $secondPart] = explode('/', $target, 2);

            if (str_starts_with($firstPart, 'coquibot') || str_contains($secondPart, 'toolkit')) {
                $result = $this->toolkitInstaller->install($target);
                $context->io->success($result['message']);
                return;
            }

            $result = $this->skillInstaller->install($firstPart, $secondPart);
            $context->io->success($result['message']);
        } catch (\Throwable $e) {
            $context->io->error('Install failed: ' . $e->getMessage());
        }
    }

    private function remove(ToolkitReplContext $context, string $target): void
    {
        if ($target === '') {
            $context->io->error('Usage: /mods remove <identifier>');
            return;
        }

        try {
            if (str_contains($target, '/')) {
                $message = $this->toolkitInstaller->remove($target);
                $context->io->success($message);
                return;
            }

            $message = $this->skillInstaller->remove($target, purge: true);
            $context->io->success($message);
        } catch (\Throwable $e) {
            $context->io->error('Remove failed: ' . $e->getMessage());
        }
    }

    private function listSkills(ToolkitReplContext $context): void
    {
        $skills = $this->skillInstaller->list();
        if ($skills === []) {
            $context->io->text('No skills installed from Coqui Mods.');
            return;
        }

        $rows = [];
        foreach ($skills as $skill) {
            $rows[] = [$skill['name'], $skill['version'], $skill['status'], $skill['source']];
        }

        $context->io->table(['Name', 'Version', 'Status', 'Source'], $rows);
    }

    private function listToolkits(ToolkitReplContext $context): void
    {
        $toolkits = $this->toolkitInstaller->list();
        if ($toolkits === []) {
            $context->io->text('No toolkits installed from Coqui Mods.');
            return;
        }

        $rows = [];
        foreach ($toolkits as $toolkit) {
            $rows[] = [$toolkit['package'], $toolkit['constraint'], $toolkit['status']];
        }

        $context->io->table(['Package', 'Constraint', 'Status'], $rows);
    }

    private function listInstalled(ToolkitReplContext $context): void
    {
        $skills = $this->skillInstaller->list();
        $toolkits = $this->toolkitInstaller->list();

        if ($skills === [] && $toolkits === []) {
            $context->io->text('No mods installed from agentcoqui.com.');
            return;
        }

        if ($skills !== []) {
            $context->io->section('Skills');
            $rows = [];
            foreach ($skills as $skill) {
                $rows[] = [$skill['name'], $skill['version'], $skill['status'], $skill['source']];
            }
            $context->io->table(['Name', 'Version', 'Status', 'Source'], $rows);
        }

        if ($toolkits !== []) {
            $context->io->section('Toolkits');
            $rows = [];
            foreach ($toolkits as $toolkit) {
                $rows[] = [$toolkit['package'], $toolkit['constraint'], $toolkit['status']];
            }
            $context->io->table(['Package', 'Constraint', 'Status'], $rows);
        }
    }

    private function update(ToolkitReplContext $context, string $target): void
    {
        if ($target === '') {
            $context->io->error('Usage: /mods update <identifier>');
            return;
        }

        try {
            if (str_contains($target, '/')) {
                $result = $this->toolkitInstaller->update($target);
                $context->io->success($result['message']);
                return;
            }

            $result = $this->skillInstaller->update($target);
            $context->io->success($result['message']);
        } catch (\Throwable $e) {
            $context->io->error('Update failed: ' . $e->getMessage());
        }
    }
}