#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHAR Build Script for PHP Composer MCP Server
 *
 * This script creates a standalone PHAR executable from the MCP server code.
 */

// Enable phar creation (required for building)
if (! Phar::canWrite()) {
    echo "Error: PHAR writing is disabled. Set phar.readonly=Off in php.ini\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);
$buildDir = __DIR__;
$pharFile = $buildDir . '/php-composer-mcp.phar';
$stubFile = $projectRoot . '/bin/mcp-server.php';
$mainScriptBackup = $stubFile . '.backup';
$configFile = $buildDir . '/build-config.json';
$configBackup = $configFile . '.backup';

/**
 * Restore backup files to their original locations
 */
function restoreBackups()
{
    global $mainScriptBackup, $stubFile, $configBackup, $configFile;

    if (file_exists($mainScriptBackup)) {
        echo "Restoring main script from backup...\n";
        rename($mainScriptBackup, $stubFile);
    }
    if (file_exists($configBackup)) {
        echo "Restoring config from backup...\n";
        rename($configBackup, $configFile);
    }
}

/**
 * Signal handler for cleanup on interrupt
 */
function signalHandler(int $signal)
{
    echo "\n\nReceived signal $signal, cleaning up...\n";
    restoreBackups();
    exit(1);
}

// Register signal handlers for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, 'signalHandler');  // Ctrl+C
    pcntl_signal(SIGTERM, 'signalHandler'); // kill command
}

// Register shutdown function to ensure cleanup on any exit
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\nFatal error detected, cleaning up...\n";
        restoreBackups();
    }
});

// Get version from git
echo "Getting version from git...\n";
$version = trim(shell_exec('cd ' . escapeshellarg($projectRoot) . ' && git describe --tags --always --dirty 2>/dev/null') ?: 'dev-unknown');
echo "Version: $version\n";

// Backup and update version in main script
echo "Updating version in main script...\n";
copy($stubFile, $mainScriptBackup);
$mainScriptContent = file_get_contents($stubFile);
$mainScriptContent = str_replace('__VERSION__', $version, $mainScriptContent);
file_put_contents($stubFile, $mainScriptContent);

// Backup and update version in build config
echo "Updating version in build configuration...\n";
copy($configFile, $configBackup);
$configContent = file_get_contents($configFile);
$configContent = str_replace('__VERSION__', $version, $configContent);
file_put_contents($configFile, $configContent);

// Remove existing PHAR if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

echo "Building PHAR: $pharFile\n";

try {
    // Create new PHAR
    $phar = new Phar($pharFile);
    $phar->startBuffering();

    // Set signature algorithm
    $phar->setSignatureAlgorithm(Phar::SHA256);

    // Add all project files (excluding unwanted directories and files)
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $excludePatterns = [
        '/\.git/',
        '/build/',
        '/tests/',
        '/\.phpunit/',
        '/phpunit\.xml/',
        '/\.php-cs-fixer\.php/',
        '/\.php-cs-fixer\.cache/',
        '/phpstan\.neon/',
        '/composer\.lock/',
        '/\.gitignore/',
        '/README\.md/',
        '/LICENSE/',
        '/\.DS_Store/',
    ];

    $includedFiles = 0;
    foreach ($iterator as $file) {
        $relativePath = str_replace($projectRoot . '/', '', $file->getPathname());

        // Skip excluded files/directories
        $exclude = false;
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, '/' . $relativePath)) {
                $exclude = true;

                break;
            }
        }

        if ($exclude) {
            continue;
        }

        // Add file to PHAR
        $phar->addFile($file->getPathname(), $relativePath);
        $includedFiles++;

        if ($includedFiles % 100 === 0) {
            echo "Added $includedFiles files...\n";
        }
    }

    echo "Total files added: $includedFiles\n";

    // Use the main script as stub (already updated with version)
    echo "Creating PHAR stub from main script...\n";
    $stubContent = file_get_contents($stubFile);
    // Add __HALT_COMPILER() at the end for PHAR
    $stub = $stubContent . "\n__HALT_COMPILER();";

    $phar->setStub($stub);
    $phar->stopBuffering();

    // Make the PHAR executable
    chmod($pharFile, 0755);

    echo "PHAR created successfully: $pharFile\n";
    echo "Size: " . number_format(filesize($pharFile)) . " bytes\n";

    // Restore original files
    echo "Restoring original files...\n";
    restoreBackups();

} catch (Exception $e) {
    echo "Error creating PHAR: " . $e->getMessage() . "\n";

    // Restore original files on error
    restoreBackups();

    exit(1);
}
