#!/usr/bin/env php
<?php

declare(strict_types=1);

// Detect if running from PHAR or source
// Check if source autoload exists to determine context
$sourceAutoload = __DIR__ . '/../vendor/autoload.php';
$isPhar = ! file_exists($sourceAutoload);

if ($isPhar) {
    Phar::mapPhar('php-composer-mcp.phar');
}

define('VERSION', '__VERSION__');

// Autoload from PHAR or source tree
if ($isPhar) {
    /** @phpstan-ignore-next-line */
    require_once 'phar://php-composer-mcp.phar/vendor/autoload.php';
} else {
    require_once $sourceAutoload;
}

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

// Handle command line arguments
if (isset($argv[1])) {
    switch ($argv[1]) {
        case '-v':
        case '--version':
            echo "PHP Composer MCP Server v" . VERSION . "\n";
            exit(0);
        case '-h':
        case '--help':
            echo "PHP Composer MCP Server v" . VERSION . "\n";
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
        ->withServerInfo('PHP Composer MCP Server', VERSION)
        ->build();

    // Discover MCP tools via attributes in the Tools directory
    $basePath = $isPhar ? 'phar://php-composer-mcp.phar' : dirname(__DIR__);
    $server->discover(
        basePath: $basePath,
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
