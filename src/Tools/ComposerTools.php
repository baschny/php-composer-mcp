<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\PackagistService;
use App\Services\ComposerService;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

class ComposerTools
{
    private PackagistService $packagistService;
    private ComposerService $composerService;

    public function __construct()
    {
        $this->packagistService = new PackagistService();
        $this->composerService = new ComposerService();
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
    #[McpTool(name: 'search_packages', description: 'Search for PHP composer packages on Packagist.org')]
    public function searchPackages(
        #[Schema(type: 'string', minLength: 2)]
        string $query,
        #[Schema(type: 'integer', minimum: 1, maximum: 100)]
        int $perPage = 15
    ): array {
        return $this->packagistService->search($query, $perPage);
    }

    /**
     * Get detailed information about a specific package.
     *
     * Fetches comprehensive information about a package from Packagist including
     * all versions, maintainers, dependencies, and statistics.
     *
     * @param string $packageName The full package name (vendor/package)
     * @return array<mixed>
     */
    #[McpTool(name: 'get_package_info', description: 'Get detailed information about a PHP composer package')]
    public function getPackageInfo(
        #[Schema(type: 'string', pattern: '^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$')]
        string $packageName
    ): array {
        return $this->packagistService->getPackage($packageName);
    }

    /**
     * Read and parse a composer.json file.
     *
     * Reads a composer.json file from the specified path and returns its
     * parsed contents as an array.
     *
     * @param string $path Absolute path to the composer.json file
     * @return array<mixed>
     */
    #[McpTool(name: 'read_composer_json', description: 'Read and parse a composer.json file')]
    public function readComposerJson(
        #[Schema(type: 'string', minLength: 1)]
        string $path
    ): array {
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
     * @return array<mixed>
     */
    #[McpTool(name: 'analyze_project', description: 'Analyze a Composer project for issues and improvements')]
    public function analyzeProject(
        #[Schema(type: 'string', minLength: 1)]
        string $projectPath
    ): array {
        // Validate project directory
        if (!is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project directory not found: {$projectPath}");
        }

        $composerJsonPath = rtrim($projectPath, '/') . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            throw new \InvalidArgumentException("composer.json not found in: {$projectPath}");
        }

        // Read composer.json
        $composerJson = $this->readComposerJson($composerJsonPath);

        // Validate the project
        $validation = $this->composerService->validateProject($projectPath);

        // Get installed packages
        $installed = $this->composerService->getInstalledPackages($projectPath);

        // Check for outdated packages
        $outdated = $this->composerService->getOutdatedPackages($projectPath);

        // Audit for security vulnerabilities
        $security = $this->composerService->auditPackages($projectPath);

        // Generate suggestions based on the analysis
        $suggestions = [];

        if (!$validation['valid']) {
            $suggestions[] = [
                'type' => 'validation',
                'severity' => 'error',
                'message' => 'Project validation failed. Run `composer validate` for details.',
                'details' => $validation,
            ];
        }

        if (count($outdated['outdated']) > 0) {
            $suggestions[] = [
                'type' => 'outdated',
                'severity' => 'warning',
                'message' => 'Found ' . count($outdated['outdated']) . ' outdated package(s). Consider updating them.',
                'packages' => array_keys($outdated['outdated']),
            ];
        }

        if ($security['summary']['has_vulnerabilities'] ?? false) {
            $suggestions[] = [
                'type' => 'security',
                'severity' => 'critical',
                'message' => 'Found ' . $security['summary']['total'] . ' security vulnerabilit(ies). Update affected packages immediately!',
                'details' => $security['summary'],
            ];
        }

        return [
            'project' => [
                'name' => $composerJson['name'],
                'path' => $projectPath,
                'type' => is_string($composerJson['type'] ?? null) ? $composerJson['type'] : 'library',
            ],
            'validation' => $validation,
            'dependencies' => [
                'total' => count($installed['installed']),
                'require' => count($composerJson['require']),
                'require-dev' => count($composerJson['require-dev']),
            ],
            'outdated' => [
                'total' => count($outdated['outdated']),
                'packages' => array_values($outdated['outdated']),
            ],
            'security' => $security,
            'suggestions' => $suggestions,
            'summary' => [
                'total_packages' => count($installed['installed']),
                'outdated_count' => count($outdated['outdated']),
                'security_issues' => $security['summary']['total'] ?? 0,
                'validation_errors' => count($validation['errors']),
                'validation_warnings' => count($validation['warnings']),
            ],
        ];
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
        // Validate project directory
        if (!is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project directory not found: {$projectPath}");
        }

        // Check for outdated packages
        $outdated = $this->composerService->getOutdatedPackages($projectPath);

        $upgrades = [];
        foreach ($outdated['outdated'] as $name => $package) {
            $currentVersion = $package['version'] ?? 'unknown';
            $latestVersion = $package['latest'] ?? 'unknown';
            $latestStatus = $package['latest-status'] ?? 'unknown';

            // Determine update type
            $updateType = $this->determineUpdateType($currentVersion, $latestVersion, $latestStatus);

            // Skip major updates if not requested
            if ($updateType === 'major' && !$includeMajor) {
                continue;
            }

            $upgrades[] = [
                'package' => $name,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'type' => $updateType,
                'status' => $latestStatus,
                'description' => $package['description'] ?? '',
            ];
        }

        // Sort by update type priority (patch < minor < major)
        usort($upgrades, function ($a, $b) {
            $priority = ['patch' => 1, 'minor' => 2, 'major' => 3];
            return ($priority[$a['type']] ?? 4) <=> ($priority[$b['type']] ?? 4);
        });

        return [
            'upgrades' => $upgrades,
            'summary' => [
                'total' => count($upgrades),
                'by_type' => [
                    'patch' => count(array_filter($upgrades, fn($u) => $u['type'] === 'patch')),
                    'minor' => count(array_filter($upgrades, fn($u) => $u['type'] === 'minor')),
                    'major' => count(array_filter($upgrades, fn($u) => $u['type'] === 'major')),
                ],
                'included_major' => $includeMajor,
            ],
        ];
    }

    /**
     * Determine the update type based on version comparison.
     *
     * @param string $current Current version
     * @param string $latest Latest version
     * @param string $status Latest status from composer
     * @return string Update type: 'major', 'minor', 'patch', or 'unknown'
     */
    private function determineUpdateType(string $current, string $latest, string $status): string
    {
        // Use composer's status if available
        if ($status === 'semver-safe-update' || $status === 'update-possible') {
            // Parse versions to determine type
            $currentParts = $this->parseVersion($current);
            $latestParts = $this->parseVersion($latest);

            if ($currentParts && $latestParts) {
                if ($currentParts['major'] !== $latestParts['major']) {
                    return 'major';
                }
                if ($currentParts['minor'] !== $latestParts['minor']) {
                    return 'minor';
                }
                if ($currentParts['patch'] !== $latestParts['patch']) {
                    return 'patch';
                }
            }
        }

        return 'unknown';
    }

    /**
     * Parse a semantic version string.
     *
     * @param string $version Version string
     * @return array{major: int, minor: int, patch: int}|null Parsed version or null
     */
    private function parseVersion(string $version): ?array
    {
        // Remove 'v' prefix if present
        $version = ltrim($version, 'v');
        
        // Match semantic versioning pattern
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            return [
                'major' => (int) $matches[1],
                'minor' => (int) $matches[2],
                'patch' => (int) $matches[3],
            ];
        }

        return null;
    }
}
