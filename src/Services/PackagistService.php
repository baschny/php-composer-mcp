<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class PackagistService
{
    private const BASE_URL = 'https://packagist.org';

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'PHP-Composer-MCP/1.0',
            ],
        ]);
    }

    /**
     * Search for packages on Packagist.
     *
     * @param string $query Search query
     * @param int $perPage Results per page (max 100)
     * @return array{results: array<mixed>, total: int}
     * @throws \RuntimeException If the request fails
     */
    public function search(string $query, int $perPage = 15): array
    {
        try {
            $response = $this->client->get('/search.json', [
                'query' => [
                    'q' => $query,
                    'per_page' => min($perPage, 100),
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (! is_array($data)) {
                throw new \RuntimeException('Invalid response from Packagist API');
            }

            return [
                'results' => $data['results'] ?? [],
                'total' => $data['total'] ?? 0,
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                'Failed to search packages: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get detailed information about a package.
     *
     * @param string $packageName Full package name (vendor/package)
     * @return array<mixed> Package information
     * @throws \RuntimeException If the request fails
     */
    public function getPackage(string $packageName): array
    {
        try {
            $response = $this->client->get("/packages/{$packageName}.json");

            $data = json_decode((string) $response->getBody(), true);

            if (! is_array($data)) {
                throw new \RuntimeException('Invalid response from Packagist API');
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Failed to get package information for '{$packageName}': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get information about a specific package version.
     *
     * @param string $packageName Full package name (vendor/package)
     * @param string $version Version constraint (e.g., "1.0.0", "dev-main")
     * @return array<mixed> Version information
     * @throws \RuntimeException If the request fails
     */
    public function getPackageVersion(string $packageName, string $version): array
    {
        $packageData = $this->getPackage($packageName);

        if (! isset($packageData['package']['versions'][$version])) {
            throw new \RuntimeException(
                "Version '{$version}' not found for package '{$packageName}'"
            );
        }

        return $packageData['package']['versions'][$version];
    }

    /**
     * Get all versions of a package.
     *
     * @param string $packageName Full package name (vendor/package)
     * @return array<string, array<mixed>> Versions indexed by version string
     * @throws \RuntimeException If the request fails
     */
    public function getPackageVersions(string $packageName): array
    {
        $packageData = $this->getPackage($packageName);

        return $packageData['package']['versions'] ?? [];
    }

    /**
     * Get statistics for a package.
     *
     * @param string $packageName Full package name (vendor/package)
     * @return array{downloads: array<mixed>, dependents: int, suggesters: int, favers: int}
     * @throws \RuntimeException If the request fails
     */
    public function getPackageStats(string $packageName): array
    {
        try {
            $response = $this->client->get("/packages/{$packageName}/stats.json");

            $data = json_decode((string) $response->getBody(), true);

            if (! is_array($data)) {
                throw new \RuntimeException('Invalid response from Packagist API');
            }

            return [
                'downloads' => $data['downloads'] ?? [],
                'dependents' => $data['dependents'] ?? 0,
                'suggesters' => $data['suggesters'] ?? 0,
                'favers' => $data['favers'] ?? 0,
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Failed to get statistics for package '{$packageName}': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
