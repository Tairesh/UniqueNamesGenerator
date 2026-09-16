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
    docker compose down -v
    docker compose build
