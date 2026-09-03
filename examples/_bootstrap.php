<?php

declare(strict_types=1);

/*
 * Shared setup for the examples: find the autoloader and read required
 * environment variables with a message a human can act on.
 */

$autoload = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "no autoloader found — run `composer install` in the package root first\n");
    exit(2);
}
require $autoload;

/** Read an environment variable or exit with a message naming it. */
function requireEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        fwrite(STDERR, "{$name} is not set — export it from your secret store, never from source\n");
        exit(2);
    }

    return trim($value);
}
