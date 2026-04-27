<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\ComposerService;
use App\Services\PackagistService;
use App\Services\UpgradePlanner;
use App\Services\VersionFilter;
use Composer\Semver\Semver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

class ComposerTools
{
    private PackagistService $packagistService;
    private ComposerService $composerService;
    private VersionFilter $versionFilter;
    private UpgradePlanner $upgradePlanner;

    public function __construct()
    {
        $this->versionFilter = new VersionFilter();
        $this->packagistService = new PackagistService(null, $this->versionFilter);
        $this->composerService = new ComposerService();
        $this->upgradePlanner = new UpgradePlanner($this->packagistService, $this->versionFilter);
    }

    /**
     * Search for packages on Packagist.
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
     * Get information about a specific package, with token-saving filters.
     *
     * Returns a slim version of the Packagist payload by default: per-version
     * noise fields (authors, dist, source, autoload, support, funding, ...)
     * are dropped, versions are sorted newest-first, and dev branches are
     * excluded. Use `versionConstraint` to restrict the result to a specific
     * range (e.g. `^12.0`) and `relevantRequires` to also keep specific
     * entries from the per-version `require` map (e.g. `["typo3/cms-core"]`,
     * `["laravel/framework"]`).
     *
     * @param string $packageName The full package name (vendor/package).
     * @param string|null $versionConstraint Composer constraint (e.g. `^12.0`, `~11.5.0`, `>=12.4 <13`).
     * @param bool $includeDev Include dev branches like `dev-main` / `*-dev`.
     * @param bool $verbose Return full per-version data (require-dev, full require map).
     * @param array<int, string> $relevantRequires Package names to always keep in each version's `require` map.
     * @param int $maxVersions Cap on the number of returned versions (newest first). Default 25, 0 disables the cap.
     * @return array<mixed>
     */
    #[McpTool(name: 'get_package_info', description: 'Get filtered, slim package info from Packagist.org. Use versionConstraint (e.g. ^12.0) to limit versions and relevantRequires (e.g. ["typo3/cms-core"]) to keep specific require entries.')]
    public function getPackageInfo(
        #[Schema(type: 'string', pattern: '^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$')]
        string $packageName,
        #[Schema(type: 'string')]
        ?string $versionConstraint = null,
        #[Schema(type: 'boolean')]
        bool $includeDev = false,
        #[Schema(type: 'boolean')]
        bool $verbose = false,
        #[Schema(type: 'array', items: ['type' => 'string'])]
        array $relevantRequires = [],
        #[Schema(type: 'integer', minimum: 0, maximum: 500)]
        int $maxVersions = 25
    ): array {
        return $this->packagistService->getPackageFiltered($packageName, [
            'versionConstraint' => $versionConstraint,
            'includeDev' => $includeDev,
            'verbose' => $verbose,
            'relevantRequires' => $relevantRequires,
            'maxVersions' => $maxVersions > 0 ? $maxVersions : null,
        ]);
    }

    /**
     * Find versions of a package that satisfy an upgrade target constraint.
     *
     * Returns a compact summary: the latest matching version (with `php` and
     * the optional `frameworkPackage` requires only) plus, when
     * `latestOnly=false`, the list of candidate versions.
     *
     * @param string $packageName Full package name (vendor/package).
     * @param string $targetConstraint Composer constraint to match (e.g. `^12.0`, `^10.0`, `^5.0`).
     * @param string|null $frameworkPackage Optional framework package whose require constraint should be surfaced (e.g. `typo3/cms-core`, `laravel/framework`).
     * @param bool $latestOnly Return only the single best match (default: true).
     * @param bool $includeDev Include dev branches.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'find_compatible_versions', description: 'Find versions of a package that satisfy a Composer constraint. Compact response designed for upgrade planning across any framework.')]
    public function findCompatibleVersions(
        #[Schema(type: 'string', pattern: '^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$')]
        string $packageName,
        #[Schema(type: 'string', minLength: 1)]
        string $targetConstraint,
        #[Schema(type: 'string')]
        ?string $frameworkPackage = null,
        #[Schema(type: 'boolean')]
        bool $latestOnly = true,
        #[Schema(type: 'boolean')]
        bool $includeDev = false
    ): array {
        return $this->upgradePlanner->findCompatibleVersions(
            $packageName,
            $targetConstraint,
            $frameworkPackage,
            $latestOnly,
            $includeDev
        );
    }

    /**
     * Plan a major framework upgrade for a project (TYPO3, Neos/Flow,
     * Laravel, CakePHP, Symfony, ...) in a single MCP call.
     *
     * Replaces dozens of `get_package_info` round trips with a compact,
     * grouped report: the framework's compatible version, extensions ready
     * vs. blocked, and (optionally) plain dependencies.
     *
     * @param string $projectPath Path to the project directory containing composer.json.
     * @param string $frameworkPackage Framework package name (e.g. `typo3/cms-core`, `laravel/framework`, `neos/neos`, `neos/flow`, `cakephp/cakephp`, `symfony/symfony`).
     * @param string $targetConstraint Composer constraint for the desired framework version (e.g. `^12.0`, `^11.0`, `^5.0`).
     * @param bool $includeDependencies If true, also report non-framework dependencies (with their latest stable version).
     * @return array<string, mixed>
     */
    #[McpTool(name: 'plan_framework_upgrade', description: 'Plan a major framework upgrade (TYPO3, Neos/Flow, Laravel, CakePHP, Symfony, ...). Classifies project packages into framework/extensions/dependencies and resolves compatible versions in one call. Replaces many get_package_info calls.')]
    public function planFrameworkUpgrade(
        #[Schema(type: 'string', minLength: 1)]
        string $projectPath,
        #[Schema(type: 'string', pattern: '^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$')]
        string $frameworkPackage,
        #[Schema(type: 'string', minLength: 1)]
        string $targetConstraint,
        #[Schema(type: 'boolean')]
        bool $includeDependencies = false
    ): array {
        if (! is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project directory not found: {$projectPath}");
        }

        return $this->upgradePlanner->planFrameworkUpgrade(
            $projectPath,
            $frameworkPackage,
            $targetConstraint,
            $includeDependencies
        );
    }

    /**
     * Read and parse a composer.json file.
     *
     * @param string $path Absolute path to the composer.json file
     * @return array<mixed>
     */
    #[McpTool(name: 'read_composer_json', description: 'Read and parse a composer.json file')]
    public function readComposerJson(
        #[Schema(type: 'string', minLength: 1)]
        string $path
    ): array {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        if (! is_readable($path)) {
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
     * Analyze a Composer project. Compact by default; pass `verbose=true`
     * for the full payload (full outdated list, full security advisories,
     * raw `composer validate` output).
     *
     * @param string $projectPath Path to the project directory containing composer.json.
     * @param bool $verbose Include full per-package details and raw validation output.
     * @return array<mixed>
     */
    #[McpTool(name: 'analyze_project', description: 'Analyze a Composer project. Compact by default; set verbose=true for full details.')]
    public function analyzeProject(
        #[Schema(type: 'string', minLength: 1)]
        string $projectPath,
        #[Schema(type: 'boolean')]
        bool $verbose = false
    ): array {
        if (! is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project directory not found: {$projectPath}");
        }

        $composerJsonPath = rtrim($projectPath, '/') . '/composer.json';
        if (! file_exists($composerJsonPath)) {
            throw new \InvalidArgumentException("composer.json not found in: {$projectPath}");
        }

        $composerJson = $this->readComposerJson($composerJsonPath);
        $validation = $this->composerService->validateProject($projectPath);
        $installed = $this->composerService->getInstalledPackages($projectPath);
        $outdated = $this->composerService->getOutdatedPackages($projectPath);
        $security = $this->composerService->auditPackages($projectPath);

        $suggestions = [];
        if (! $validation['valid']) {
            $suggestions[] = [
                'type' => 'validation',
                'severity' => 'error',
                'message' => 'Project validation failed. Run `composer validate` for details.',
            ];
        }
        if (count($outdated['outdated']) > 0) {
            $suggestions[] = [
                'type' => 'outdated',
                'severity' => 'warning',
                'message' => 'Found ' . count($outdated['outdated']) . ' outdated package(s).',
            ];
        }
        if ($security['summary']['has_vulnerabilities'] ?? false) {
            $suggestions[] = [
                'type' => 'security',
                'severity' => 'critical',
                'message' => 'Found ' . $security['summary']['total'] . ' security vulnerability(ies). Update affected packages immediately.',
            ];
        }

        $compactOutdated = [];
        foreach ($outdated['outdated'] as $name => $package) {
            $current = is_string($package['version'] ?? null) ? $package['version'] : 'unknown';
            $latest = is_string($package['latest'] ?? null) ? $package['latest'] : 'unknown';
            $compactOutdated[] = [
                'name' => is_string($name) ? $name : (is_string($package['name'] ?? null) ? $package['name'] : 'unknown'),
                'current' => $current,
                'latest' => $latest,
                'type' => $this->determineUpdateType($current, $latest, is_string($package['latest-status'] ?? null) ? $package['latest-status'] : 'update-possible'),
            ];
        }

        $compactAdvisories = [];
        foreach ($security['advisories'] as $packageName => $advisories) {
            if (! is_array($advisories)) {
                continue;
            }
            foreach ($advisories as $advisory) {
                if (! is_array($advisory)) {
                    continue;
                }
                $compactAdvisories[] = [
                    'package' => is_string($packageName) ? $packageName : ($advisory['packageName'] ?? 'unknown'),
                    'cve' => $advisory['cve'] ?? null,
                    'title' => $advisory['title'] ?? null,
                    'affectedVersions' => $advisory['affectedVersions'] ?? null,
                    'reportedAt' => $advisory['reportedAt'] ?? null,
                    'link' => $advisory['link'] ?? null,
                ];
            }
        }

        $result = [
            'project' => [
                'name' => $composerJson['name'] ?? null,
                'path' => $projectPath,
                'type' => is_string($composerJson['type'] ?? null) ? $composerJson['type'] : 'library',
            ],
            'validation' => [
                'valid' => $validation['valid'],
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ],
            'dependencies' => [
                'total' => count($installed['installed']),
                'require' => is_array($composerJson['require'] ?? null) ? count($composerJson['require']) : 0,
                'require-dev' => is_array($composerJson['require-dev'] ?? null) ? count($composerJson['require-dev']) : 0,
            ],
            'outdated' => [
                'total' => count($outdated['outdated']),
                'packages' => $compactOutdated,
            ],
            'security' => [
                'total' => $security['summary']['total'] ?? 0,
                'has_vulnerabilities' => $security['summary']['has_vulnerabilities'] ?? false,
                'advisories' => $compactAdvisories,
            ],
            'suggestions' => $suggestions,
            'summary' => [
                'total_packages' => count($installed['installed']),
                'outdated_count' => count($outdated['outdated']),
                'security_issues' => $security['summary']['total'] ?? 0,
                'validation_errors' => count($validation['errors']),
                'validation_warnings' => count($validation['warnings']),
            ],
        ];

        if ($verbose) {
            $result['outdated']['packages_full'] = array_values($outdated['outdated']);
            $result['security']['advisories_full'] = $security['advisories'];
            $result['validation']['output'] = $validation['output'];
        }

        return $result;
    }

    /**
     * Suggest available package upgrades for a project.
     *
     * @param string $projectPath Path to the project directory.
     * @param bool $includeMajor Include major version upgrades (default: false).
     * @param string|null $targetConstraint Optional Composer constraint that the latest version must satisfy (e.g. `^12.0` for TYPO3 12, `^10.0` for Laravel 10).
     * @param string|null $packageFilter Optional regex matched against the package name (e.g. `^typo3/`, `^laravel/`, `^symfony/`).
     * @param bool $verbose Include each package's description and any extra metadata.
     * @return array{upgrades: array<array{package: string, current: string, latest: string, type: string}>, summary: array<string, mixed>}
     */
    #[McpTool(name: 'suggest_upgrades', description: 'Suggest available package upgrades. Supports targetConstraint (e.g. ^12.0) and packageFilter (regex) for focused upgrades.')]
    public function suggestUpgrades(
        #[Schema(type: 'string', minLength: 1)]
        string $projectPath,
        #[Schema(type: 'boolean')]
        bool $includeMajor = false,
        #[Schema(type: 'string')]
        ?string $targetConstraint = null,
        #[Schema(type: 'string')]
        ?string $packageFilter = null,
        #[Schema(type: 'boolean')]
        bool $verbose = false
    ): array {
        if (! is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project directory not found: {$projectPath}");
        }

        $outdated = $this->composerService->getOutdatedPackages($projectPath);

        $compiledFilter = null;
        if (is_string($packageFilter) && $packageFilter !== '') {
            $compiledFilter = '/' . str_replace('/', '\\/', $packageFilter) . '/i';
            if (@preg_match($compiledFilter, '') === false) {
                throw new \InvalidArgumentException("Invalid packageFilter regex: {$packageFilter}");
            }
        }

        $upgrades = [];
        foreach ($outdated['outdated'] as $name => $package) {
            $packageName = is_string($name) ? $name : (is_string($package['name'] ?? null) ? $package['name'] : 'unknown');
            $currentVersion = is_string($package['version'] ?? null) ? $package['version'] : 'unknown';
            $latestVersion = is_string($package['latest'] ?? null) ? $package['latest'] : 'unknown';
            $latestStatus = is_string($package['latest-status'] ?? null) ? $package['latest-status'] : 'unknown';

            if ($compiledFilter !== null && preg_match($compiledFilter, $packageName) !== 1) {
                continue;
            }

            if (is_string($targetConstraint) && $targetConstraint !== '' && $latestVersion !== 'unknown') {
                try {
                    if (! Semver::satisfies($latestVersion, $targetConstraint)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }

            $updateType = $this->determineUpdateType($currentVersion, $latestVersion, $latestStatus);

            if ($updateType === 'major' && ! $includeMajor) {
                continue;
            }

            $entry = [
                'package' => $packageName,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'type' => $updateType,
                'status' => $latestStatus,
            ];
            if ($verbose) {
                $entry['description'] = is_string($package['description'] ?? null) ? $package['description'] : '';
            }
            $upgrades[] = $entry;
        }

        usort($upgrades, function ($a, $b) {
            $priority = ['patch' => 1, 'minor' => 2, 'major' => 3];

            return ($priority[$a['type']] ?? 4) <=> ($priority[$b['type']] ?? 4);
        });

        return [
            'upgrades' => $upgrades,
            'summary' => [
                'total' => count($upgrades),
                'by_type' => [
                    'patch' => count(array_filter($upgrades, fn ($u) => $u['type'] === 'patch')),
                    'minor' => count(array_filter($upgrades, fn ($u) => $u['type'] === 'minor')),
                    'major' => count(array_filter($upgrades, fn ($u) => $u['type'] === 'major')),
                ],
                'included_major' => $includeMajor,
                'targetConstraint' => $targetConstraint,
                'packageFilter' => $packageFilter,
            ],
        ];
    }

    /**
     * Determine the update type based on version comparison.
     */
    private function determineUpdateType(string $current, string $latest, string $status): string
    {
        if ($status === 'semver-safe-update' || $status === 'update-possible') {
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
     * @return array{major: int, minor: int, patch: int}|null
     */
    private function parseVersion(string $version): ?array
    {
        $version = ltrim($version, 'v');

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
