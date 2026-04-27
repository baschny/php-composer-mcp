# PHP Composer MCP Server

A [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) server for Composer package management and project maintenance. This server provides AI assistants with tools to interact with Packagist.org, analyze Composer projects, and assist with dependency management.

## Features

- 🔍 **Package Search**: Search for packages on Packagist.org
- 📦 **Package Information**: Get detailed information about specific packages
- 📄 **Composer.json Parsing**: Read and analyze composer.json files
- 🔧 **Project Analysis**: Analyze dependencies, outdated packages, security vulnerabilities, and project health
- ⬆️ **Upgrade Suggestions**: Get intelligent upgrade recommendations with semantic versioning support
- 🔒 **Security Auditing**: Check for known security vulnerabilities using Composer's audit feature
- ✅ **Project Validation**: Validate composer.json and composer.lock files

## Requirements

- PHP >= 8.4
- Composer
- An MCP-compatible client (Claude Desktop, Cursor IDE, Warp, etc.)

## Installation

### Use the PHAR

1. Download the latest PHAR from the [releases page](https://github.com/baschny/php-composer-mcp/releases).
2. Move the PHAR to a location in your `$PATH` (e.g. `/usr/local/bin/mcp-server.phar`).
3. Make the PHAR executable: `chmod +x mcp-server.phar`.

### From source

1. Clone or download this repository:
```bash
git clone https://github.com/baschny/php-composer-mcp.git php-composer-mcp
cd php-composer-mcp
```

2. Install dependencies:
```bash
composer install
```

3. Make the server executable:
```bash
chmod +x bin/mcp-server.php
```

## Configuration

* Claude Desktop: https://modelcontextprotocol.io/docs/develop/connect-local-servers
* Cursor IDE: https://cursor.com/docs/context/mcp
* Warp: https://docs.warp.dev/knowledge-and-collaboration/mcp

Add this JSON to your preferred AI tool as described in one of the documentation above:

```json
{
  "mcpServers": {
    "php-composer": {
      "command": "/usr/local/bin/php-composer-mcp.phar",
      "args": []
    }
  }
}
```

## Token-saving design

Packagist payloads for popular packages are huge — `typo3/cms-core` alone is ~965 KB (277 versions × heavy per-version metadata). For agent workflows, that quickly drowns the context window.

The MCP tools in this server are designed to keep responses small by default:

- `get_package_info` filters versions by Composer constraint, sorts newest-first, strips noise fields (`authors`, `dist`, `source`, `autoload`, `support`, `funding`, …) and limits the per-version `require` map to `php` plus any keys you ask for via `relevantRequires`. Filtering `typo3/cms-core` to `^12.0` brings the payload down from ~965 KB to ~24 KB.
- `find_compatible_versions` returns just the latest version of a single package matching a target constraint, with PHP and the optional framework requirement only — typically a few hundred bytes.
- `plan_framework_upgrade` is a single-call replacement for the dozens of `get_package_info` calls an agent would otherwise make during a major framework upgrade.
- `analyze_project` and `suggest_upgrades` ship a compact response by default; pass `verbose=true` to get the full payload when you need it.

## Available Tools

### 1. `search_packages`

Search for packages on Packagist.org.

**Parameters:**
- `query` (string, required): Search query (package name, keyword, or description)
- `perPage` (integer, optional): Number of results per page (default: 15, max: 100)

### 2. `get_package_info`

Get filtered, slim package info from Packagist.org.

**Parameters:**
- `packageName` (string, required): Full package name (vendor/package)
- `versionConstraint` (string, optional): Composer constraint to filter versions (e.g. `^12.0`, `~11.5.0`, `>=12.4 <13`)
- `includeDev` (boolean, optional): Include dev branches (`dev-main`, `*-dev`). Default: `false`
- `verbose` (boolean, optional): Return full per-version data (`require-dev`, full `require` map). Default: `false`
- `relevantRequires` (array of strings, optional): Package names whose entries to keep in each version's `require` map (e.g. `["typo3/cms-core"]`, `["laravel/framework"]`). Default: only `php` is kept in non-verbose mode.
- `maxVersions` (integer, optional): Cap on returned versions (newest first). Default: `25`. Use `0` to disable the cap.

### 3. `find_compatible_versions`

Find versions of a single package that satisfy an upgrade target constraint. Designed for upgrade planning across any framework.

**Parameters:**
- `packageName` (string, required): Full package name (vendor/package)
- `targetConstraint` (string, required): Composer constraint (e.g. `^12.0`, `^10.0`, `^5.0`)
- `frameworkPackage` (string, optional): Framework package whose required constraint should be surfaced (e.g. `typo3/cms-core`, `laravel/framework`)
- `latestOnly` (boolean, optional): Return only the single best match. Default: `true`
- `includeDev` (boolean, optional): Include dev branches. Default: `false`

**Returns:** A compact `{ package, targetConstraint, latest, candidates, totalCandidates }` object.

### 4. `plan_framework_upgrade`

Plan a major framework upgrade (TYPO3, Neos/Flow, Laravel, CakePHP, Symfony, …) in a single MCP call. Replaces dozens of `get_package_info` calls with a compact, grouped report.

**Parameters:**
- `projectPath` (string, required): Path to the project directory containing `composer.json`
- `frameworkPackage` (string, required): Framework package name (e.g. `typo3/cms-core`, `laravel/framework`, `neos/neos`, `neos/flow`, `cakephp/cakephp`, `symfony/symfony`)
- `targetConstraint` (string, required): Composer constraint for the desired framework version (e.g. `^12.0`, `^11.0`, `^5.0`)
- `includeDependencies` (boolean, optional): If `true`, also report non-framework dependencies and their latest stable. Default: `false`

**Returns:** `{ framework, extensions_ready, extensions_blocked, dependencies?, summary }` where each entry is a small object with `name`, `current`, `suggested`, and a `breaking` flag.

### 5. `read_composer_json`

Read and parse a composer.json file.

**Parameters:**
- `path` (string, required): Absolute path to the composer.json file

### 6. `analyze_project`

Analyze a Composer project for issues and improvements. Compact by default.

**Parameters:**
- `projectPath` (string, required): Path to the project directory
- `verbose` (boolean, optional): Include full per-package details and raw `composer validate` output. Default: `false`

**Returns:**
- Project metadata and configuration
- Validation results (composer.json and composer.lock)
- Dependency statistics
- Compact outdated packages list
- Compact security advisory report
- Actionable suggestions with severity levels

### 7. `suggest_upgrades`

Suggest available package upgrades for a project.

**Parameters:**
- `projectPath` (string, required): Path to the project directory
- `includeMajor` (boolean, optional): Include major version upgrades. Default: `false`
- `targetConstraint` (string, optional): Composer constraint the latest version must satisfy (e.g. `^12.0` for TYPO3 12, `^10.0` for Laravel 10)
- `packageFilter` (string, optional): Regex matched against the package name (e.g. `^typo3/`, `^laravel/`, `^symfony/`)
- `verbose` (boolean, optional): Include each package's description. Default: `false`

**Returns:**
- List of available upgrades
- Categorization by update type (patch, minor, major)
- Current and latest versions
- Summary statistics

## Example Use Cases

Here are some realistic scenarios where this MCP server can help AI assistants manage PHP projects:

### 🚀 Scenario 1: First-Time Project Analysis

**AI Prompt:**
> "I just inherited this legacy PHP project. Can you analyze it and tell me what state it's in? Check for outdated dependencies, security issues, and validation problems."

**What the AI will do:**
1. Use `analyze_project` to get a comprehensive health report
2. Identify outdated packages and security vulnerabilities
3. Check if composer.json and composer.lock are valid
4. Provide a prioritized list of issues to address

**Expected Output:**
```
Project Analysis for 'acme/legacy-app':

✅ Validation: Passed
📦 Dependencies: 47 total (42 require, 5 require-dev)
⚠️  Outdated: 12 packages need updates
🔒 Security: 2 vulnerabilities found (CRITICAL)

Top Priority Issues:
1. [CRITICAL] Security vulnerability in symfony/http-foundation 3.4.2
2. [WARNING] 12 packages are outdated
3. [INFO] Consider updating to PHP 8.4 for better performance

Recommendation: Address security issues immediately, then plan package updates.
```

### 📦 Scenario 2: Major Framework Upgrade

The single biggest token win in this server: `plan_framework_upgrade` resolves an entire upgrade plan in one call.

**AI Prompt:**
> "I have this TYPO3 11 project and want to upgrade to TYPO3 12. Which packages need updating and which ones don't have a compatible version yet?"

**What the AI will do:**
1. Call `plan_framework_upgrade` with `frameworkPackage = "typo3/cms-core"` and `targetConstraint = "^12.0"`.
2. (Optional) Call `find_compatible_versions` for any flagged blocked extension to confirm.

The same flow works for any framework — just change the `frameworkPackage`:

- TYPO3: `frameworkPackage = "typo3/cms-core"`, `targetConstraint = "^12.0"` (or `"^13.0"`)
- Neos / Flow: `frameworkPackage = "neos/neos"` or `"neos/flow"`, `targetConstraint = "^9.0"`
- Laravel: `frameworkPackage = "laravel/framework"`, `targetConstraint = "^11.0"`
- CakePHP: `frameworkPackage = "cakephp/cakephp"`, `targetConstraint = "^5.0"`
- Symfony: `frameworkPackage = "symfony/symfony"`, `targetConstraint = "^7.0"`

The tool classifies every package in `require` / `require-dev` as:

- **framework** — the framework package itself or its vendor-prefixed siblings (e.g. `typo3/cms-*` for TYPO3, `symfony/*` for Symfony, `laravel/*` for Laravel, `neos/*` for Neos, `cakephp/*` for CakePHP).
- **extension** — any other package whose own `require` map references the framework package.
- **dependency** — everything else (only emitted when `includeDependencies=true`).

**Expected Output:**
```
Framework Upgrade Plan (typo3/cms-core ^12.0):

Framework: typo3/cms-core 11.5.35 → v12.4.45 (available)

Extensions ready (10):
- typo3/cms-backend       11.5.35 → v12.4.45  [breaking]  (core)
- typo3/cms-frontend      11.5.35 → v12.4.45  [breaking]  (core)
- georgringer/news         9.3.1 → 11.0.0     [breaking]
- b13/container             2.3.0 → 2.3.2
- mask/mask                 7.1.0 → 8.3.1     [breaking]

Extensions blocked (1):
- old/extension  (current: 1.2.0 — no version satisfying ^12.0 found on Packagist)

Summary: framework=typo3/cms-core target=^12.0 ready=10 blocked=1 total_extensions=11
```

### 🔧 Scenario 3: Laravel Project Maintenance

**AI Prompt:**
> "My Laravel 10 application is running in production. Show me only safe (patch and minor) updates I can apply without risk."

**What the AI will do:**
1. Use `suggest_upgrades` with `includeMajor: false` (default)
2. Categorize updates by type
3. Check security advisories
4. Provide a safe update plan

**Expected Output:**
```
Safe Upgrades Available:

Patch Updates (8 packages - Recommended):
- laravel/framework: 10.43.0 → 10.48.4 (bug fixes)
- symfony/console: 6.4.3 → 6.4.4 (bug fixes)
- guzzlehttp/guzzle: 7.8.1 → 7.8.2 (security fix)
- ... (5 more)

Minor Updates (3 packages - Low Risk):
- spatie/laravel-permission: 6.3.0 → 6.7.0 (new features)
- league/flysystem: 3.23.0 → 3.25.1 (enhancements)
- nesbot/carbon: 2.72.0 → 2.72.5 (improvements)

Excluded from this list:
- 2 major version updates (use includeMajor: true to see them)

Recommendation: Apply patch updates immediately. Test minor updates in staging.
Run: composer update --no-dev --optimize-autoloader
```

### 🔍 Scenario 4: Finding Alternative Packages

**AI Prompt:**
> "I'm using 'doctrine/orm' in my project but it feels too heavy. Search for lightweight PHP ORM alternatives and show me the most popular options."

**What the AI will do:**
1. Use `search_packages` with query "lightweight orm"
2. Use `get_package_info` for top results
3. Compare downloads, GitHub stars, and maintenance status
4. Provide recommendations

**Expected Output:**
```
Lightweight ORM Alternatives:

1. cycle/orm (Most Popular)
   - Downloads: 500K+
   - Favorers: 1.2K
   - Description: PHP DataMapper ORM and Data Modelling Engine
   - Pro: Fast, no annotations, better performance
   - Con: Different paradigm from Doctrine

2. illuminate/database (Laravel's Query Builder)
   - Downloads: 100M+
   - Favorers: 2.5K  
   - Description: Eloquent ORM (can use standalone)
   - Pro: Simple, excellent docs, Active Record pattern
   - Con: Tied to Laravel ecosystem

3. propel/propel
   - Downloads: 5M+
   - Favorers: 1.1K
   - Description: Propel2 is an ORM for PHP
   - Pro: Battle-tested, good performance
   - Con: Less active development

Recommendation: For a greenfield project, try cycle/orm.
For Laravel-style syntax, use illuminate/database.
```

### 🛡️ Scenario 5: Security Audit Before Deployment

**AI Prompt:**
> "I'm about to deploy my application to production. Run a security check and make sure there are no vulnerable dependencies."

**What the AI will do:**
1. Use `analyze_project` to run security audit
2. Check composer.json validation
3. Verify composer.lock is up to date
4. List all security advisories

**Expected Output:**
```
Pre-Deployment Security Audit:

🔒 Security Status: VULNERABILITIES FOUND

❌ CRITICAL Issues (1):
1. symfony/http-kernel (5.4.20)
   - CVE-2023-XXXX: HTTP Host Header Injection
   - Affected: 5.4.0 to 5.4.24
   - Fixed in: 5.4.25+
   - Action: Update immediately!

⚠️  HIGH Issues (2):
2. guzzlehttp/psr7 (2.4.0)
   - Improper header parsing
   - Fixed in: 2.4.5+

3. league/flysystem (3.12.0)
   - Path traversal vulnerability  
   - Fixed in: 3.12.3+

✅ Validation: composer.json and composer.lock are valid
❌ Lock file: Out of sync (run composer update)

🚨 DEPLOYMENT BLOCKED
Reason: Critical security vulnerabilities must be resolved first.

Recommended Actions:
1. Run: composer update symfony/http-kernel guzzlehttp/psr7 league/flysystem
2. Test thoroughly in staging
3. Re-run security audit
4. Update composer.lock in version control
```

### 📚 Scenario 6: Researching Package Options

**AI Prompt:**
> "I need to add PDF generation to my PHP project. Show me the most popular PDF generation libraries and their pros/cons."

**What the AI will do:**
1. Use `search_packages` with query "pdf generation"
2. Use `get_package_info` for each top result
3. Compare features, maintenance, and popularity
4. Provide detailed comparison

**Expected Output:**
```
Top PDF Generation Libraries:

1. dompdf/dompdf ⭐ Most Popular
   - Downloads: 50M+
   - GitHub Stars: 10K+
   - Latest: 2.0.4 (actively maintained)
   - Pros: HTML/CSS to PDF, easy to use, pure PHP
   - Cons: Slower for large documents, limited CSS support
   - Best for: Simple HTML to PDF conversion

2. tecnickcom/tcpdf
   - Downloads: 35M+
   - GitHub Stars: 3.5K
   - Latest: 6.7.4
   - Pros: Rich features, supports UTF-8, no dependencies
   - Cons: Complex API, older codebase
   - Best for: Complex PDF requirements, Unicode support

3. mpdf/mpdf
   - Downloads: 15M+
   - GitHub Stars: 4K+
   - Latest: 8.2.3
   - Pros: Good HTML/CSS support, modern codebase
   - Cons: Requires more memory
   - Best for: Better CSS support than dompdf

4. knplabs/snappy (wkhtmltopdf wrapper)
   - Downloads: 5M+
   - Requires: External binary (wkhtmltopdf)
   - Pros: Excellent rendering, full CSS support
   - Cons: External dependency, harder to deploy
   - Best for: Complex layouts, exact browser rendering

Recommendation:
- Simple needs → dompdf/dompdf
- Better CSS → mpdf/mpdf
- Perfect rendering → knplabs/snappy (if you can install wkhtmltopdf)
- Maximum compatibility → tecnickcom/tcpdf
```

---

These examples demonstrate how the MCP server enables AI assistants to provide intelligent, context-aware help for real-world PHP/Composer workflows.

## Development

### Code Quality

Run PHPStan for static analysis:
```bash
vendor/bin/phpstan analyse
```

Run PHP CS Fixer to format code:
```bash
vendor/bin/php-cs-fixer fix
```

### Testing the Server

Test the server directly:
```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php bin/mcp-server.php
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License - see LICENSE file for details

## Resources

- [Model Context Protocol Documentation](https://modelcontextprotocol.io/)
- [PHP MCP Server SDK](https://github.com/php-mcp/server)
- [Packagist API Documentation](https://packagist.org/apidoc)
- [Composer Documentation](https://getcomposer.org/doc/)

## Author

Ernesto Baschny - eb@cron.eu
