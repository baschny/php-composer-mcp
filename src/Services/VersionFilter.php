<?php

declare(strict_types=1);

namespace App\Services;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Helpers to filter and slim down Packagist version metadata.
 *
 * Packagist's package.json payloads can be huge (~1 MB for popular packages
 * like typo3/cms-core). For most upgrade-planning use cases the agent only
 * needs a small subset: a compact list of versions matching a constraint,
 * plus the framework/php requirements. This service centralizes that logic.
 */
class VersionFilter
{
    /**
     * Per-version fields that are always dropped — they are never useful for
     * upgrade planning and account for the bulk of the payload size.
     *
     * @var array<int, string>
     */
    private const DROP_VERSION_FIELDS = [
        'authors',
        'keywords',
        'homepage',
        'support',
        'funding',
        'dist',
        'source',
        'autoload',
        'autoload-dev',
        'extra',
        'default-branch',
        'license',
        'description',
    ];

    /**
     * Per-version fields kept only when verbose=true.
     *
     * @var array<int, string>
     */
    private const VERBOSE_ONLY_FIELDS = [
        'require-dev',
    ];

    /**
     * Top-level package fields that are dropped from the payload to save tokens.
     * `maintainers` is replaced with a count.
     *
     * @var array<int, string>
     */
    private const DROP_PACKAGE_FIELDS = [
        'maintainers',
    ];

    private VersionParser $versionParser;

    public function __construct()
    {
        $this->versionParser = new VersionParser();
    }

    /**
     * Filter, sort, and slim a Packagist /packages/{name}.json payload.
     *
     * @param array<mixed> $payload Raw decoded Packagist payload (should contain a 'package' key).
     * @param array{
     *     versionConstraint?: string|null,
     *     includeDev?: bool,
     *     verbose?: bool,
     *     relevantRequires?: array<int, string>,
     *     maxVersions?: int|null,
     * } $options
     * @return array<mixed>
     */
    public function filterPayload(array $payload, array $options = []): array
    {
        $constraint = $options['versionConstraint'] ?? null;
        $includeDev = $options['includeDev'] ?? false;
        $verbose = $options['verbose'] ?? false;
        $relevantRequires = $options['relevantRequires'] ?? [];
        $maxVersions = $options['maxVersions'] ?? null;

        if (! isset($payload['package']) || ! is_array($payload['package'])) {
            return $payload;
        }

        $package = $payload['package'];

        // Slim the top-level package object.
        $maintainerCount = is_array($package['maintainers'] ?? null) ? count($package['maintainers']) : 0;
        foreach (self::DROP_PACKAGE_FIELDS as $field) {
            unset($package[$field]);
        }
        $package['maintainers_count'] = $maintainerCount;

        $versions = $package['versions'] ?? [];
        if (! is_array($versions)) {
            $versions = [];
        }

        $filtered = $this->filterVersions($versions, [
            'versionConstraint' => $constraint,
            'includeDev' => $includeDev,
            'verbose' => $verbose,
            'relevantRequires' => $relevantRequires,
            'maxVersions' => $maxVersions,
        ]);

        $package['versions'] = $filtered['versions'];
        $package['versions_total'] = $filtered['total'];
        $package['versions_returned'] = count($filtered['versions']);
        if ($constraint !== null && $constraint !== '') {
            $package['versionConstraint'] = $constraint;
        }

        $payload['package'] = $package;

        return $payload;
    }

    /**
     * Filter and slim a versions map.
     *
     * @param array<string, array<mixed>> $versions
     * @param array{
     *     versionConstraint?: string|null,
     *     includeDev?: bool,
     *     verbose?: bool,
     *     relevantRequires?: array<int, string>,
     *     maxVersions?: int|null,
     * } $options
     * @return array{versions: array<int, array<mixed>>, total: int}
     */
    public function filterVersions(array $versions, array $options = []): array
    {
        $constraint = $options['versionConstraint'] ?? null;
        $includeDev = $options['includeDev'] ?? false;
        $verbose = $options['verbose'] ?? false;
        $relevantRequires = $options['relevantRequires'] ?? [];
        $maxVersions = $options['maxVersions'] ?? null;

        $total = count($versions);

        // 1. Sort newest-first by normalized version.
        $entries = array_values($versions);
        usort($entries, function ($a, $b) {
            $av = is_string($a['version_normalized'] ?? null) ? $a['version_normalized'] : '0.0.0.0';
            $bv = is_string($b['version_normalized'] ?? null) ? $b['version_normalized'] : '0.0.0.0';

            try {
                if (Comparator::greaterThan($av, $bv)) {
                    return -1;
                }
                if (Comparator::lessThan($av, $bv)) {
                    return 1;
                }

                return 0;
            } catch (\Throwable) {
                return strcmp($bv, $av);
            }
        });

        // 2. Drop dev versions unless requested.
        if (! $includeDev) {
            $entries = array_values(array_filter($entries, function ($v) {
                $version = is_string($v['version'] ?? null) ? $v['version'] : '';

                return ! $this->isDevVersion($version);
            }));
        }

        // 3. Filter by version constraint.
        if (is_string($constraint) && $constraint !== '') {
            $entries = array_values(array_filter($entries, function ($v) use ($constraint) {
                $version = is_string($v['version'] ?? null) ? $v['version'] : null;
                if ($version === null || $version === '') {
                    return false;
                }

                try {
                    return Semver::satisfies($version, $constraint);
                } catch (\Throwable) {
                    return false;
                }
            }));
        }

        // 4. Cap to maxVersions.
        if (is_int($maxVersions) && $maxVersions > 0) {
            $entries = array_slice($entries, 0, $maxVersions);
        }

        // 5. Strip noise fields per version.
        $entries = array_map(
            fn (array $v): array => $this->slimVersion($v, $verbose, $relevantRequires),
            $entries
        );

        return [
            'versions' => $entries,
            'total' => $total,
        ];
    }

    /**
     * Strip noise fields from a single version entry.
     *
     * @param array<mixed> $version
     * @param array<int, string> $relevantRequires
     * @return array<mixed>
     */
    public function slimVersion(array $version, bool $verbose, array $relevantRequires = []): array
    {
        foreach (self::DROP_VERSION_FIELDS as $field) {
            unset($version[$field]);
        }

        if (! $verbose) {
            foreach (self::VERBOSE_ONLY_FIELDS as $field) {
                unset($version[$field]);
            }

            // Slim the require map: keep only php and any user-supplied "relevant" packages.
            if (isset($version['require']) && is_array($version['require'])) {
                $keep = ['php' => true];
                foreach ($relevantRequires as $name) {
                    if ($name !== '') {
                        $keep[strtolower($name)] = true;
                    }
                }
                $slim = [];
                foreach ($version['require'] as $name => $constraint) {
                    if (! is_string($name)) {
                        continue;
                    }
                    if (isset($keep[strtolower($name)])) {
                        $slim[$name] = $constraint;
                    }
                }
                $version['require'] = $slim;
            }
        }

        return $version;
    }

    /**
     * Pick the latest version matching a constraint.
     *
     * @param array<string, array<mixed>> $versions
     * @return array<mixed>|null
     */
    public function findLatestMatching(array $versions, string $constraint, bool $includeDev = false): ?array
    {
        $filtered = $this->filterVersions($versions, [
            'versionConstraint' => $constraint,
            'includeDev' => $includeDev,
            'verbose' => true, // keep require so callers can inspect it
            'maxVersions' => 1,
        ]);

        return $filtered['versions'][0] ?? null;
    }

    /**
     * Detect dev branches like "dev-main", "1.x-dev", "9.0.x-dev".
     */
    public function isDevVersion(string $version): bool
    {
        if ($version === '') {
            return true;
        }
        if (str_starts_with($version, 'dev-')) {
            return true;
        }
        if (str_ends_with($version, '-dev')) {
            return true;
        }
        if (str_contains($version, 'x-dev')) {
            return true;
        }

        return false;
    }

    /**
     * Compare two versions and tell whether `latest` is a major bump from `current`.
     */
    public function isMajorBump(string $current, string $latest): bool
    {
        $currentMajor = $this->extractMajor($current);
        $latestMajor = $this->extractMajor($latest);
        if ($currentMajor === null || $latestMajor === null) {
            return false;
        }

        return $currentMajor !== $latestMajor;
    }

    /**
     * Extract the major version from any version string.
     */
    public function extractMajor(string $version): ?int
    {
        try {
            $normalized = $this->versionParser->normalize($version);
        } catch (\Throwable) {
            $normalized = ltrim($version, 'v');
        }

        if (preg_match('/^(\d+)\./', $normalized, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
