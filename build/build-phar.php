#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHAR Build Script for PHP Composer MCP Server
 * 
 * This script creates a standalone PHAR executable from the MCP server code.
 */

// Enable phar creation (required for building)
if (!Phar::canWrite()) {
    echo "Error: PHAR writing is disabled. Set phar.readonly=Off in php.ini\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);
$buildDir = __DIR__;
$pharFile = $buildDir . '/php-composer-mcp.phar';
$stubFile = $projectRoot . '/bin/mcp-server.php';

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
    
    // Create a custom stub that properly handles the PHAR environment
    $stub = <<<'STUB'
#!/usr/bin/env php
<?php

declare(strict_types=1);

Phar::mapPhar('php-composer-mcp.phar');

// Set up autoloading from within the PHAR
require_once 'phar://php-composer-mcp.phar/vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

// Handle command line arguments
if (isset($argv[1])) {
    switch ($argv[1]) {
        case '-v':
        case '--version':
            echo "PHP Composer MCP Server v1.0.0\n";
            exit(0);
        case '-h':
        case '--help':
            echo "PHP Composer MCP Server v1.0.0\n";
            echo "\nUsage: {$argv[0]} [options]\n";
            echo "\nOptions:\n";
            echo "  -v, --version    Show version information\n";
            echo "  -h, --help       Show this help message\n";
            echo "\nWithout options, starts the MCP server on stdio transport.\n";
            exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$argv[1]}\n");
            fwrite(STDERR, "Use -h or --help for usage information.\n");
            exit(1);
    }
}

try {
    // Build server configuration
    $server = Server::make()
        ->withServerInfo('PHP Composer MCP Server', '1.0.0')
        ->build();

    // Discover MCP tools via attributes in the Tools directory
    $server->discover(
        basePath: 'phar://php-composer-mcp.phar',
        scanDirs: ['src/Tools']
    );

    // Start listening via stdio transport
    $transport = new StdioServerTransport();
    $server->listen($transport);

} catch (\Throwable $e) {
    fwrite(STDERR, "[CRITICAL ERROR] " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

__HALT_COMPILER();
STUB;
    
    $phar->setStub($stub);
    $phar->stopBuffering();
    
    // Make the PHAR executable
    chmod($pharFile, 0755);
    
    echo "PHAR created successfully: $pharFile\n";
    echo "Size: " . number_format(filesize($pharFile)) . " bytes\n";
    
} catch (Exception $e) {
    echo "Error creating PHAR: " . $e->getMessage() . "\n";
    exit(1);
}