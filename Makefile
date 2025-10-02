# PHP Composer MCP Server - Build Makefile

.PHONY: help install build clean test

# Default target
help:
	@echo "Available targets:"
	@echo "  install    - Install dependencies"
	@echo "  build      - Build PHAR executable"
	@echo "  clean      - Clean build directory"
	@echo "  test       - Test the built PHAR"
	@echo "  release    - Build and create release package"

install:
	composer install --no-dev --optimize-autoloader

build: install
	@echo "Building PHAR executable..."
	@mkdir -p build
	php -d phar.readonly=0 build/build-phar.php

clean:
	@echo "Cleaning build directory..."
	rm -rf build/*.phar build/*.tar.gz build/*.zip

test: build
	@echo "Testing PHAR executable..."
	@if [ -f build/php-composer-mcp.phar ]; then \
		echo "PHAR file exists: build/php-composer-mcp.phar"; \
		ls -la build/php-composer-mcp.phar; \
		echo "Testing PHAR version command..."; \
		./build/php-composer-mcp.phar -v; \
		echo "Testing PHAR help command..."; \
		./build/php-composer-mcp.phar --help; \
		echo "PHAR tests completed successfully!"; \
	else \
		echo "ERROR: PHAR file not found!"; \
		exit 1; \
	fi

release: clean build test
	@echo "Creating release package..."
	@VERSION=$$(./build/php-composer-mcp.phar -v | sed 's/.*v//'); \
	cd build && tar -czf php-composer-mcp-$$VERSION.tar.gz php-composer-mcp.phar; \
	echo "Release package created: build/php-composer-mcp-$$VERSION.tar.gz"; \
	echo "Version: $$VERSION"
