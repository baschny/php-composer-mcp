<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Plans framework upgrades by classifying project packages and finding
 * compatible versions. Designed to replace dozens of get_package_info
 * round trips with a single tool call.
 */
class UpgradePlanner
{
    private PackagistService $packagist;
    private VersionFilter $versionFilter;

    public function __construct(
        ?PackagistService $packagist = null,
        ?VersionFilter $versionFilter = null
    ) {
        $this->versionFilter = $versionFilter ?? new VersionFilter();
        $this->packagist = $packagist ?? new PackagistService(null, $this->versionFilter);
    }

    /**
     * Find versions of `$packageName` compatible with `$targetConstraint`.
     *
     * @return array{
     *     package: string,
     *     targetConstraint: string,
     *     latest: array<string, mixed>|null,
     *     candidates: array<int, array{version: string, time: string|null}>,
     *     totalCandidates: int,
     * }
     */
    public function findCompatibleVersions(
        string $packageName,
        string $targetConstraint,
        ?string $frameworkPackage = null,
        bool $latestOnly = true,
        bool $includeDev = false
    ): array {
        try {
            $payload = $this->packagist->getPackage($packageName);
        } catch (\Throwable $e) {
            return [
                'package' => $packageName,
                'targetConstraint' => $targetConstraint,
                'latest' => null,
                'candidates' => [],
                'totalCandidates' => 0,
                'error' => $e->getMessage(),
            ];
        }

        $versions = [];
        if (isset($payload['package']['versions']) && is_array($payload['package']['versions'])) {
            $versions = $payload['package']['versions'];
        }

        $relevantRequires = [];
        if (is_string($frameworkPackage) && $frameworkPackage !== '') {
            $relevantRequires[] = $frameworkPackage;
        }

        $filtered = $this->versionFilter->filterVersions($versions, [
            'versionConstraint' => $targetConstraint,
            'includeDev' => $includeDev,
            'verbose' => false,
            'relevantRequires' => $relevantRequires,
            'maxVersions' => $latestOnly ? 1 : 25,
        ]);

        $latest = null;
        $candidates = [];
        foreach ($filtered['versions'] as $idx => $entry) {
            $version = is_string($entry['version'] ?? null) ? $entry['version'] : '';
            $time = is_string($entry['time'] ?? null) ? $entry['time'] : null;
            $requires = is_array($entry['require'] ?? null) ? $entry['require'] : [];

            if ($idx === 0) {
                $latest = [
                    'version' => $version,
                    'time' => $time,
                    'requires' => $requires,
                ];
            }
            $candidates[] = [
                'version' => $version,
                'time' => $time,
            ];
        }

        return [
            'package' => $packageName,
            'targetConstraint' => $targetConstraint,
            'latest' => $latest,
            'candidates' => $latestOnly ? [] : $candidates,
            'totalCandidates' => count($filtered['versions']),
        ];
    }

    /**
     * Plan a framework upgrade for an installed project.
     *
     * @return array<string, mixed>
     */
    public function planFrameworkUpgrade(
        string $projectPath,
        string $frameworkPackage,
        string $targetConstraint,
        bool $includeDependencies = false
    ): array {
        $composerJson = $this->readComposerJson($projectPath);
        $installed = $this->readInstalledMap($projectPath);

        /** @var array<string, string> $allRequires */
        $allRequires = [];
        foreach (['require', 'require-dev'] as $section) {
            $entries = $composerJson[$section] ?? [];
            if (is_array($entries)) {
                foreach ($entries as $name => $constraint) {
                    if (is_string($name) && is_string($constraint) && ! str_starts_with($name, 'ext-') && $name !== 'php') {
                        $allRequires[$name] = $constraint;
                    }
                }
            }
        }

        $vendorPrefix = $this->vendorUmbrellaPrefix($frameworkPackage);

        $framework = [
            'package' => $frameworkPackage,
            'current' => $installed[$frameworkPackage] ?? null,
            'suggested' => null,
            'available' => false,
        ];
        $extensionsReady = [];
        $extensionsBlocked = [];
        $dependencies = [];

        // Resolve framework first so we know its compatible PHP/etc.
        $frameworkResult = $this->findCompatibleVersions(
            $frameworkPackage,
            $targetConstraint,
            null,
            true
        );
        if ($frameworkResult['latest'] !== null) {
            $framework['suggested'] = $frameworkResult['latest']['version'] ?? null;
            $framework['available'] = true;
        }

        foreach ($allRequires as $name => $constraint) {
            if ($name === $frameworkPackage) {
                continue;
            }

            $current = $installed[$name] ?? $constraint;
            $isFrameworkSibling = $vendorPrefix !== null && str_starts_with($name, $vendorPrefix);

            $dependsOnFramework = false;
            if (! $isFrameworkSibling) {
                $dependsOnFramework = $this->packageRequiresFramework($name, $frameworkPackage);
            }

            if ($isFrameworkSibling || $dependsOnFramework) {
                $result = $this->findCompatibleVersions(
                    $name,
                    $targetConstraint,
                    $frameworkPackage,
                    true
                );
                if ($result['latest'] !== null) {
                    $suggested = is_string($result['latest']['version'] ?? null) ? $result['latest']['version'] : null;
                    $entry = [
                        'name' => $name,
                        'current' => $current,
                        'suggested' => $suggested,
                        'breaking' => $suggested !== null ? $this->versionFilter->isMajorBump($current, $suggested) : false,
                    ];
                    if ($isFrameworkSibling) {
                        $entry['core'] = true;
                    }
                    $extensionsReady[] = $entry;
                } else {
                    $extensionsBlocked[] = [
                        'name' => $name,
                        'current' => $current,
                        'reason' => 'No version satisfying ' . $targetConstraint . ' found on Packagist',
                    ];
                }

                continue;
            }

            // Plain dependency — only emit when requested.
            if ($includeDependencies) {
                $result = $this->findCompatibleVersions($name, '*', null, true);
                $latest = $result['latest']['version'] ?? null;
                $dependencies[] = [
                    'name' => $name,
                    'current' => $current,
                    'latest' => $latest,
                ];
            }
        }

        $output = [
            'framework' => $framework,
            'extensions_ready' => $extensionsReady,
            'extensions_blocked' => $extensionsBlocked,
        ];
        if ($includeDependencies) {
            $output['dependencies'] = $dependencies;
        }
        $output['summary'] = [
            'framework' => $frameworkPackage,
            'target' => $targetConstraint,
            'ready' => count($extensionsReady),
            'blocked' => count($extensionsBlocked),
            'total_extensions' => count($extensionsReady) + count($extensionsBlocked),
        ];

        return $output;
    }

    /**
     * Read composer.json from a project directory.
     *
     * @return array<string, mixed>
     */
    private function readComposerJson(string $projectPath): array
    {
        $path = rtrim($projectPath, '/') . '/composer.json';
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("composer.json not found in: {$projectPath}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read composer.json: {$path}");
        }
        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new \RuntimeException("Invalid JSON in composer.json: {$path}");
        }

        return $data;
    }

    /**
     * Read installed package versions from composer.lock if present, else
     * fall back to vendor/composer/installed.json.
     *
     * @return array<string, string>
     */
    private function readInstalledMap(string $projectPath): array
    {
        $base = rtrim($projectPath, '/');
        $candidates = [
            $base . '/composer.lock' => 'lock',
            $base . '/vendor/composer/installed.json' => 'installed',
        ];

        foreach ($candidates as $path => $kind) {
            if (! file_exists($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                continue;
            }
            $packages = [];
            if ($kind === 'lock') {
                $packages = array_merge(
                    is_array($decoded['packages'] ?? null) ? $decoded['packages'] : [],
                    is_array($decoded['packages-dev'] ?? null) ? $decoded['packages-dev'] : []
                );
            } else {
                $packages = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;
            }
            $map = [];
            foreach ($packages as $pkg) {
                if (is_array($pkg) && isset($pkg['name'], $pkg['version']) && is_string($pkg['name']) && is_string($pkg['version'])) {
                    $map[$pkg['name']] = $pkg['version'];
                }
            }
            if ($map !== []) {
                return $map;
            }
        }

        return [];
    }

    /**
     * Compute the vendor "umbrella" prefix for a framework package, used to
     * detect sibling core packages.
     *
     * Examples:
     *   typo3/cms-core    -> typo3/cms-
     *   symfony/symfony   -> symfony/
     *   laravel/framework -> laravel/
     *   neos/neos         -> neos/
     *   neos/flow         -> neos/
     *   cakephp/cakephp   -> cakephp/
     */
    public function vendorUmbrellaPrefix(string $frameworkPackage): ?string
    {
        if (! str_contains($frameworkPackage, '/')) {
            return null;
        }
        [$vendor, $name] = explode('/', $frameworkPackage, 2);
        if ($vendor === '' || $name === '') {
            return null;
        }
        // typo3/cms-core, typo3/cms-backend etc. share a "cms-" sub-prefix.
        if (str_contains($name, '-')) {
            $sub = explode('-', $name, 2)[0];
            // Only treat as sub-prefix when meaningful (avoid 1-char vendors).
            if ($sub !== '' && $sub !== $name) {
                return $vendor . '/' . $sub . '-';
            }
        }

        return $vendor . '/';
    }

    /**
     * Cheaply check whether a package's latest published version requires
     * `$frameworkPackage`.
     */
    private function packageRequiresFramework(string $packageName, string $frameworkPackage): bool
    {
        try {
            $payload = $this->packagist->getPackage($packageName);
        } catch (\Throwable) {
            return false;
        }
        $versions = $payload['package']['versions'] ?? [];
        if (! is_array($versions)) {
            return false;
        }
        $latest = $this->versionFilter->findLatestMatching($versions, '*', false);
        if ($latest === null) {
            return false;
        }
        $requires = $latest['require'] ?? [];

        return is_array($requires) && isset($requires[$frameworkPackage]);
    }
}
