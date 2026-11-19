<?php

use PhpCsFixer\Finder;

// GLPI's shared php-cs-fixer baseline for plugins, from the GLPI checkout the plugin is developed
// in (the plugin in its plugins/ directory, or GLPI_DIR).
$baseline = require (getenv('GLPI_DIR') ?: __DIR__ . '/../..') . '/PluginsPhpCsFixer.php';

return $baseline(
    Finder::create()
        ->in(__DIR__)
        ->exclude(['node_modules', 'vendor'])
        ->ignoreVCSIgnored(true)
        ->name('*.php'),
);
