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
- An MCP-compatible client (Claude Desktop, Cursor IDE, etc.)

## Installation

1. Clone or download this repository:
```bash
git clone <repository-url> php-composer-mcp
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

### Claude Desktop

Add the following to your Claude Desktop configuration file:

**macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`

**Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "php-composer": {
      "command": "php",
      "args": ["/absolute/path/to/php-composer-mcp/bin/mcp-server.php"]
    }
  }
}
```

### Cursor IDE

Add to your Cursor settings (`.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "php-composer": {
      "command": "php",
      "args": ["/absolute/path/to/php-composer-mcp/bin/mcp-server.php"]
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

**Example:**
```
Search for "symfony console" packages
```

### 2. `get_package_info`

Get detailed information about a specific package.

**Parameters:**
- `packageName` (string, required): Full package name (vendor/package)

**Example:**
```
Get information about symfony/console
```

### 3. `read_composer_json`

Read and parse a composer.json file.

**Parameters:**
- `path` (string, required): Absolute path to the composer.json file

**Example:**
```
Read /path/to/project/composer.json
```

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

**Example:**
```
Analyze the Composer project at /path/to/my-project
```

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

**Example:**
```
Suggest safe upgrades for /path/to/my-project
```
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
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | bin/mcp-server.php
```

## Architecture

```
php-composer-mcp/
├── bin/
│   └── mcp-server.php          # Main MCP server entry point
├── src/
│   ├── Tools/
│   │   └── ComposerTools.php   # MCP tool definitions
│   └── Services/
│       └── PackagistService.php # Packagist API client
├── composer.json
└── README.md
```

## Roadmap

- [x] Basic MCP server setup
- [x] Package search functionality
- [x] Package information retrieval
- [x] Composer.json file reading
- [x] Project dependency analysis
- [x] Security vulnerability scanning
- [x] Upgrade path suggestions
- [x] Integration with Composer commands
- [x] Project validation
- [x] Outdated package detection
- [ ] composer.json modification tools
- [ ] Automated upgrade execution
- [ ] Interactive dependency resolution
- [ ] Custom repository support
- [ ] Platform requirements checking

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

Ernst Jendritzki - ernst@cron.eu
