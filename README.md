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

## Available Tools

### 1. `search_packages`

Search for packages on Packagist.org.

**Parameters:**
- `query` (string, required): Search query (package name, keyword, or description)
- `perPage` (integer, optional): Number of results per page (default: 15, max: 100)

### 2. `get_package_info`

Get detailed information about a specific package.

**Parameters:**
- `packageName` (string, required): Full package name (vendor/package)

### 3. `read_composer_json`

Read and parse a composer.json file.

**Parameters:**
- `path` (string, required): Absolute path to the composer.json file

### 4. `analyze_project`

Analyze a Composer project for issues and improvements.

**Parameters:**
- `projectPath` (string, required): Path to the project directory

**Returns:**
- Project metadata and configuration
- Validation results (composer.json and composer.lock)
- Dependency statistics
- Outdated packages list
- Security vulnerability report
- Actionable suggestions with severity levels

### 5. `suggest_upgrades`

Suggest available package upgrades for a project.

**Parameters:**
- `projectPath` (string, required): Path to the project directory
- `includeMajor` (boolean, optional): Include major version upgrades (default: false)

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

### 📦 Scenario 2: TYPO3 Major Version Upgrade

**AI Prompt:**
> "I have this TYPO3 11 project and want to upgrade to TYPO3 12. Check the composer.json: what packages need updating and are there any compatibility issues to be aware of?"

**What the AI will do:**
1. Use `analyze_project` to understand current dependencies
2. Use `suggest_upgrades` with `includeMajor: true` to see TYPO3 12 availability
3. Use `get_package_info` to check TYPO3 12 requirements
4. Search for TYPO3 12 compatible versions of extensions

**Expected Output:**
```
TYPO3 Upgrade Analysis:

Current: TYPO3 11.5.35
Target: TYPO3 12.4.11 (latest)

Core Packages (6 packages):
- typo3/cms-core: 11.5.35 → 12.4.11 [MAJOR]
- typo3/cms-backend: 11.5.35 → 12.4.11 [MAJOR]
- ... (all core packages follow)

Extensions to Update (8 packages):
- georgringer/news: 9.3.1 → 11.0.0 [MAJOR - Breaking changes]
- b13/container: 2.3.0 → 2.3.2 [PATCH - Safe]
- mask/mask: 7.1.0 → 8.3.1 [MAJOR - Check changelog]

Blocked Extensions (2 packages):
- old/extension: No TYPO3 12 version available yet

Recommendation: Review breaking changes in georgringer/news and mask/mask.
Consider removing or replacing 'old/extension' before upgrading.
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
