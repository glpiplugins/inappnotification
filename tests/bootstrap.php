<?php

declare(strict_types=1);

// Unit tests run on the host against glpi-core's Composer autoloader (which can load
// GLPI core classes via its classmap). Only pure, DB-free logic is exercised here.
$pluginRoot = dirname(__DIR__);
$coreVendor = dirname($pluginRoot) . '/glpi-core/vendor/autoload.php';
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
