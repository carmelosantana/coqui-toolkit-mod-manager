<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract {
    use Symfony\Component\Console\Style\SymfonyStyle;

    if (!interface_exists(ReplCommandProvider::class)) {
        interface ReplCommandProvider
        {
            /**
             * @return list<ToolkitCommandHandler>
             */
            public function commandHandlers(): array;
        }
    }

    if (!class_exists(ToolkitReplContext::class)) {
        final readonly class ToolkitReplContext
        {
            public function __construct(
                public SymfonyStyle $io,
            ) {}
        }
    }

    if (!interface_exists(ToolkitCommandHandler::class)) {
        interface ToolkitCommandHandler
        {
            public function commandName(): string;

            /**
             * @return list<string>
             */
            public function subcommands(): array;

            public function usage(): string;

            public function description(): string;

            public function handle(ToolkitReplContext $context, string $arg): void;
        }
    }
}