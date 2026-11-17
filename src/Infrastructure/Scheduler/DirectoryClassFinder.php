<?php

declare(strict_types=1);

namespace Infrastructure\Scheduler;

/** @implements \IteratorAggregate<int, class-string> */
final readonly class DirectoryClassFinder implements \IteratorAggregate
{
    public function __construct(
        private string $directory,
        private string $namespace,
    ) {
    }

    /** @return \Generator<int, class-string> */
    public function getIterator(): \Generator
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $root = rtrim((string) realpath($this->directory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getRealPath(), strlen($root), -4);
            $class = rtrim($this->namespace, '\\') . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (self::isConcreteClass($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);
        yield from $classes;
    }

    // Like Laravel's command/listener discovery: a file that cannot be loaded (missing parent class,
    // parse error, not a class at all) is skipped instead of breaking the whole scan.
    /** @phpstan-assert-if-true class-string $class */
    private static function isConcreteClass(string $class): bool
    {
        try {
            return class_exists($class) && ! new \ReflectionClass($class)->isAbstract();
        } catch (\Throwable) {
            return false;
        }
    }
}
