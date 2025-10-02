# Building PHP Composer MCP Server

This document explains how to build a standalone PHAR executable of the PHP Composer MCP Server.

## Prerequisites

- PHP 8.4 or higher
- Composer
- PHAR writing must be enabled (`phar.readonly=Off` in php.ini or use `-d phar.readonly=0`)

## Build Methods

### Using Make (Recommended)

```bash
# Build the PHAR executable
make build

# Build and test
make test

# Create a release package
make release

# Clean build artifacts
make clean
```

### Using Composer Scripts

```bash
# Build PHAR (requires phar.readonly=Off in php.ini)
composer run build-phar

# Build PHAR with runtime override
composer run build-phar-check
```

### Direct Build Script

```bash
# Run the build script directly
php -d phar.readonly=0 build/build-phar.php
```

## Build Output

The build process creates:
- `build/php-composer-mcp.phar` - The standalone executable
- `build/php-composer-mcp-{version}.tar.gz` - Release package (when using `make release`)

## Testing the PHAR

After building, you can test the PHAR executable:

```bash
# Test basic execution
./build/php-composer-mcp.phar

# Check file permissions and size
ls -la build/php-composer-mcp.phar
```

## Build Configuration

The build process is configured via:
- `build/build-config.json` - Build configuration settings
- `build/build-phar.php` - Main build script
- `Makefile` - Build automation

## Troubleshooting

### PHAR readonly error
If you get a "phar.readonly" error:
```bash
# Check current setting
php -i | grep phar.readonly

# Run with override
php -d phar.readonly=0 build/build-phar.php
```

### File permissions
The built PHAR should be executable. If not:
```bash
chmod +x build/php-composer-mcp.phar
```

### Size optimization
The PHAR includes all dependencies. To reduce size:
- Ensure `composer install --no-dev` was used
- Only production dependencies are included
- Build artifacts and test files are excluded

## Distribution

The built PHAR is a self-contained executable that can be distributed without requiring Composer or the source code. Users only need PHP 8.4+ to run it.