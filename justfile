# Everything runs inside the container; nothing is installed on the host.

# Run the full test suite
default: test

# Build the test image
build:
    docker compose build

# Run phpunit; extra arguments are passed through (e.g. `just test tests/GeneratorTest.php`)
test *args:
    docker compose run --rm tests vendor/bin/phpunit {{args}}

# Run only the tests whose name matches a pattern
filter pattern:
    docker compose run --rm tests vendor/bin/phpunit --filter '{{pattern}}'

# Open a shell inside the container
shell:
    docker compose run --rm tests sh

# Reset the vendor volume and rebuild — required after composer.json changes
rebuild:
    docker compose rm -fsv
    docker compose down -v --remove-orphans
    docker compose build

# Fix code style with PHP CS Fixer
fixer:
    docker compose run --rm tests composer fixer

# PHPStan (level max)
stan:
    docker compose run --rm tests composer phpstan

# PHPMD
phpmd:
    docker compose run --rm tests composer phpmd

# Static analysis only
lint: stan phpmd

# Style, static analysis and tests
check: fixer lint test
