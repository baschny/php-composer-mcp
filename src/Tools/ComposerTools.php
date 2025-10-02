<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\PackagistService;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

class ComposerTools
{
    private PackagistService $packagistService;

    public function __construct()
    {
        $this->packagistService = new PackagistService();
    }

    /**
     * Search for packages on Packagist.
     *
     * Searches Packagist.org for packages matching the given query string.
     * Returns a list of matching packages with basic information.
     *
     * @param string $query The search query (package name, keyword, or description)
     * @param int $perPage Number of results per page (default: 15, max: 100)
     * @return array{results: array<array{name: string, description: string, downloads: int, favers: int}>, total: int}
     */
    #[McpTool(name: 'search_packages', description: 'Search for packages on Packagist.org')]
    public function searchPackages(
        #[Schema(type: 'string', minLength: 2)]
        string $query,
        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $perPage = 15
    ): array {
        // TODO: Implement package search
        return $this->packagistService->search($query, $perPage);
    }

    /**
     * Get detailed information about a specific package.
     *
     * Fetches comprehensive information about a package from Packagist including
     * all versions, maintainers, dependencies, and statistics.
     *
     * @param string $packageName The full package name (vendor/package)
     * @return array{package: array{name: string, description: string, versions: array, maintainers: array}}
     */
    #[McpTool(name: 'get_package_info', description: 'Get detailed information about a Composer package')]
    public function getPackageInfo(
        #[Schema(type: 'string', pattern: '^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$')]
        string $packageName
    ): array {
        // TODO: Implement package info retrieval
        return $this->packagistService->getPackage($packageName);
    }

    /**
     * Read and parse a composer.json file.
     *
     * Reads a composer.json file from the specified path and returns its
     * parsed contents as an array.
     *
     * @param string $path Absolute path to the composer.json file
     * @return array{name: string, require: array, require-dev: array, autoload: array}
     */
    #[McpTool(name: 'read_composer_json', description: 'Read and parse a composer.json file')]
    public function readComposerJson(
        #[Schema(type: 'string', minLength: 1)]
        string $path
    ): array {
        // TODO: Implement composer.json reading
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new \InvalidArgumentException("File not readable: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        $decoded = json_decode($content, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in file: {$path} - " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Analyze a Composer project.
     *
     * Analyzes a project's composer.json and composer.lock files to provide
     * insights about dependencies, security issues, and outdated packages.
     *
     * @param string $projectPath Path to the project directory containing composer.json
     * @return array{dependencies: array, outdated: array, security: array, suggestions: array}
     */
    #[McpTool(name: 'analyze_project', description: 'Analyze a Composer project for issues and improvements')]
    public function analyzeProject(
        #[Schema(type: 'string', minLength: 1)]
        string $projectPath
    ): array {
        // TODO: Implement project analysis
        // - Check for outdated packages
        // - Check for security vulnerabilities
        // - Analyze dependency tree
        // - Provide upgrade suggestions
        throw new \LogicException('Project analysis not yet implemented');
    }

    /**
     * Suggest package upgrades for a project.
     *
     * Analyzes the current dependencies and suggests available upgrades,
     * including major, minor, and patch updates with compatibility information.
     *
     * @param string $projectPath Path to the project directory
     * @param bool $includeMajor Include major version upgrades (default: false)
     * @return array{upgrades: array<array{package: string, current: string, latest: string, type: string}>}
     */
    #[McpTool(name: 'suggest_upgrades', description: 'Suggest available package upgrades for a project')]
    public function suggestUpgrades(
        #[Schema(type: 'string', minLength: 1)]
        string $projectPath,
        #[Schema(type: 'boolean')]
        bool $includeMajor = false
    ): array {
        // TODO: Implement upgrade suggestions
        // - Parse composer.json and composer.lock
        // - Check for available updates
        // - Categorize by update type (major, minor, patch)
        // - Check compatibility
        throw new \LogicException('Upgrade suggestions not yet implemented');
    }
}
