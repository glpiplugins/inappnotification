<?php

declare(strict_types=1);

// Unit tests run without a database. GLPI core classes come from the GLPI the tests run in
// (official image: the plugin mounted under /var/www/glpi/plugins, see tools/check.sh), else from
// the glpi-core checkout next to the plugin. Its autoloader is registered in front of the one of
// the PHPUnit installation, so the plugin's item classes load against the matching core.
$pluginRoot = dirname(__DIR__);
$glpiDir = getenv('GLPI_DIR') ?: '/var/www/glpi';
$coreVendor = is_file($glpiDir . '/vendor/autoload.php')
    ? $glpiDir . '/vendor/autoload.php'
    : dirname($pluginRoot) . '/glpi-core/vendor/autoload.php';
if (is_file($coreVendor)) {
    require_once $coreVendor;
}

if (!defined('GLPI_CONFIG_DIR')) {
    define('GLPI_CONFIG_DIR', sys_get_temp_dir() . '/inappnotification-tests-config');
}
if (!is_dir(GLPI_CONFIG_DIR)) {
    @mkdir(GLPI_CONFIG_DIR, 0777, true);
}

// PSR-4 autoloader for the plugin's own classes (GLPI registers an equivalent at runtime).
spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $prefix = 'GlpiPlugin\\Inappnotification\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $pluginRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
