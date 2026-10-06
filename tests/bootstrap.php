<?php

/*
 * Roda dentro do monorepo server.app.br com o vendor da raiz (ou com o vendor
 * do próprio package, no CI). Rode da pasta do package:
 *
 *   ../../../vendor/bin/phpunit
 *
 * O vendor da raiz tem CÓPIAS do monitoring e do risetools instaladas com
 * classmap otimizado, que ganha do PSR-4 — por isso o mapa de classes é
 * sobrescrito arquivo a arquivo com o código deste checkout (e do risetools
 * irmão, quando existir).
 */

$packageRoot = dirname(__DIR__);

$loader = null;
foreach ([$packageRoot . '/vendor', dirname($packageRoot, 3) . '/vendor'] as $vendor) {
    if (is_file($vendor . '/autoload.php') && is_dir($vendor . '/orchestra/testbench-core')) {
        $loader = require $vendor . '/autoload.php';
        break;
    }
}

if ($loader === null) {
    fwrite(STDERR, "Autoloader com orchestra/testbench não encontrado.\n");
    exit(1);
}

$override = function (string $namespace, string $src) use ($loader): void {
    if (!is_dir($src)) {
        return;
    }

    $classMap = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($src) + 1, -4);
        $classMap[$namespace . str_replace(['/', '\\'], '\\', $relative)] = $file->getPathname();
    }

    $loader->addClassMap($classMap);
    $loader->addPsr4($namespace, $src, true);
};

$override('RiseTechApps\\Monitoring\\', $packageRoot . '/src');
$override('RiseTechApps\\RiseTools\\', dirname($packageRoot) . '/risetools/src');

$loader->addPsr4('RiseTechApps\\Monitoring\\Tests\\', __DIR__, true);

// Credenciais locais de PostgreSQL/Redis: as mesmas da suíte do tenancy.
$tenancyEnv = dirname($packageRoot) . '/TenancyForLaravel/tests';
if (is_file($tenancyEnv . '/.env.testing')) {
    Dotenv\Dotenv::createUnsafeImmutable($tenancyEnv, '.env.testing')->safeLoad();
}
