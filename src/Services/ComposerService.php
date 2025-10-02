<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Service to interact with Composer via CLI commands.
 */
class ComposerService
{
    /**
     * Execute a composer command in the given directory.
     *
     * @param string $workingDirectory The project directory
     * @param array<string> $arguments Composer command arguments
     * @return array{output: string, exitCode: int}
     * @throws \RuntimeException If the command fails
     */
    private function executeComposerCommand(string $workingDirectory, array $arguments): array
    {
        $process = new Process(
            ['composer', ...$arguments],
            $workingDirectory,
            null,
            null,
            300 // 5 minutes timeout
        );

        $process->run();

        return [
            'output' => $process->getOutput(),
            'exitCode' => $process->getExitCode() ?? 0,
        ];
    }

    /**
     * Check for outdated packages in a project.
     *
     * @param string $projectPath Path to the project directory
     * @return array{installed: array<mixed>, outdated: array<mixed>}
     * @throws \RuntimeException If the command fails
     */
    public function getOutdatedPackages(string $projectPath): array
    {
        $result = $this->executeComposerCommand($projectPath, [
            'outdated',
            '--format=json',
            '--no-interaction',
        ]);

        if ($result['exitCode'] !== 0) {
            // Exit code 0 = no outdated packages, 1 = has outdated packages
            // Both are valid, only fail on other errors
            if ($result['exitCode'] > 1) {
                throw new \RuntimeException("Composer outdated command failed with exit code {$result['exitCode']}");
            }
        }

        $data = json_decode($result['output'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON output from composer outdated');
        }

        return [
            'installed' => $data['installed'] ?? [],
            'outdated' => array_filter($data['installed'] ?? [], function ($package) {
                return isset($package['latest']) && $package['version'] !== $package['latest'];
            }),
        ];
    }

    /**
     * Show information about installed packages.
     *
     * @param string $projectPath Path to the project directory
     * @return array{installed: array<mixed>}
     * @throws \RuntimeException If the command fails
     */
    public function getInstalledPackages(string $projectPath): array
    {
        $result = $this->executeComposerCommand($projectPath, [
            'show',
            '--format=json',
            '--no-interaction',
        ]);

        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("Composer show command failed with exit code {$result['exitCode']}");
        }

        $data = json_decode($result['output'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON output from composer show');
        }

        return [
            'installed' => $data['installed'] ?? [],
        ];
    }

    /**
     * Audit packages for known security vulnerabilities.
     *
     * @param string $projectPath Path to the project directory
     * @return array{advisories: array<mixed>, summary: array<string, mixed>}
     * @throws \RuntimeException If the command fails
     */
    public function auditPackages(string $projectPath): array
    {
        $result = $this->executeComposerCommand($projectPath, [
            'audit',
            '--format=json',
            '--no-interaction',
        ]);

        // Exit codes: 0 = no vulnerabilities, 1 = vulnerabilities found
        if ($result['exitCode'] > 1) {
            throw new \RuntimeException("Composer audit command failed with exit code {$result['exitCode']}");
        }

        $data = json_decode($result['output'], true);
        if (!is_array($data)) {
            // If audit isn't available or fails, return empty results
            return [
                'advisories' => [],
                'summary' => ['total' => 0],
            ];
        }

        return [
            'advisories' => $data['advisories'] ?? [],
            'summary' => [
                'total' => count($data['advisories'] ?? []),
                'has_vulnerabilities' => $result['exitCode'] === 1,
            ],
        ];
    }

    /**
     * Validate composer.json and composer.lock files.
     *
     * @param string $projectPath Path to the project directory
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     * @throws \RuntimeException If the command fails
     */
    public function validateProject(string $projectPath): array
    {
        $result = $this->executeComposerCommand($projectPath, [
            'validate',
            '--no-interaction',
            '--strict',
        ]);

        // Exit code 0 = valid, non-zero = has issues
        $isValid = $result['exitCode'] === 0;

        // Parse output for errors and warnings
        $output = $result['output'];
        $errors = [];
        $warnings = [];

        if (preg_match_all('/\[error\]\s+(.+)/i', $output, $matches)) {
            $errors = $matches[1];
        }

        if (preg_match_all('/\[warning\]\s+(.+)/i', $output, $matches)) {
            $warnings = $matches[1];
        }

        return [
            'valid' => $isValid,
            'errors' => $errors,
            'warnings' => $warnings,
            'output' => $output,
        ];
    }

    /**
     * Get a list of packages that depend on a specific package.
     *
     * @param string $projectPath Path to the project directory
     * @param string $packageName Package name to check
     * @return array{dependents: array<mixed>}
     * @throws \RuntimeException If the command fails
     */
    public function getDependents(string $projectPath, string $packageName): array
    {
        $result = $this->executeComposerCommand($projectPath, [
            'depends',
            $packageName,
            '--no-interaction',
        ]);

        // Parse output to get dependents
        $dependents = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            // Match lines like: "vendor/package version requires"
            if (preg_match('/^([a-z0-9\-_\/]+)\s+([^\s]+)/i', trim($line), $matches)) {
                if ($matches[1] !== $packageName) {
                    $dependents[] = [
                        'name' => $matches[1],
                        'version' => $matches[2],
                    ];
                }
            }
        }

        return [
            'package' => $packageName,
            'dependents' => $dependents,
            'count' => count($dependents),
        ];
    }
}