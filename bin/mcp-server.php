#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

try {
    // Build server configuration
    $server = Server::make()
        ->withServerInfo('PHP Composer MCP Server', '1.0.0')
        ->build();

    // Discover MCP tools via attributes in the Tools directory
    $server->discover(
        basePath: dirname(__DIR__),
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
