# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`chypriote/unique-names-generator` — a zero-dependency PHP library (PHP >= 7.4) that builds readable random
names such as `PlannedRhinoceros` by picking one word from each configured dictionary.

## Commands

Everything runs inside Docker — PHP, Composer and PHPUnit are not installed on the host, so never invoke
`composer` or `vendor/bin/phpunit` directly. The `justfile` wraps `docker compose`:

```bash
just                                              # full suite
just filter generator_can_shuffle_dictionaries    # single test
just test tests/GeneratorTest.php                 # single file (extra args pass through to phpunit)
just shell                                        # shell inside the container
just build                                        # build the image
just rebuild                                      # reset the vendor volume + rebuild, after composer.json changes
```

`compose.yaml` bind-mounts the source and keeps `vendor/` in a named volume, so dependencies never land on the
host and the container runs as uid/gid 1000. Because a named volume is only seeded from the image the first
time it is created, changing `composer.json` requires `just rebuild` — a plain `just build` leaves the old
dependencies in place. The empty root-owned `vendor/` directory on the host is just Docker's mount point.

There is no linter or static analyser configured. Formatting follows `.editorconfig` (4 spaces, LF, final newline).

## Architecture

`generate(int|string|null $id = null, int $attempt = 0)` is a deterministic function: the key is hashed and
the hash picks a point in the space of all possible names. Two moving parts only:

- `src/Generator.php` — the entire public API. A fluent, mutable config object (`setDictionaries`,
  `addDictionary`, `setSeparator`) plus `generate()` and `getSpaceSize()`.
- `src/dictionaries/*.php` — each file `return`s a flat array of unique lowercase strings, loaded lazily via
  `include` in `pools()`.

How a name is built:

- **A name is a number.** `x` in `[0, N)` is decoded as a mixed-radix numeral — `x % size` picks the word for
  a position, `intdiv($x, $size)` moves to the next. `N` is the product of position sizes, exposed by
  `getSpaceSize()`.
- **A position is one dictionary or a merged pool.** `setDictionaries([['adjectives', 'colors'], 'animals'])`
  makes the first position a deduplicated union of two dictionaries. The number of positions is the length of
  the name; there is no separate length setting. Pools are memoised in `$pools` and invalidated by
  `setDictionaries()`/`addDictionary()`.
- **`attempt` walks a full-cycle sequence.** `x0` comes from the first 8 bytes of the sha256, the step `g`
  from the next 8; `g` is made coprime with `N`, so `x0, x0+g, x0+2g, …` visits every point exactly once.
  That is what makes different attempts on one key guaranteed — not merely likely — to differ.
- **Repeated words are skipped, not rejected.** `compose()` returns `null` when a word occurs twice, and
  `attempt = i` means the i-th *acceptable* element of the sequence. Skipping preserves the uniqueness
  guarantee because the filter only removes elements.
- **Type is part of the key**: `generate(42)` and `generate('42')` hash differently by design.
- `readIndex()` masks the sign bit with `& PHP_INT_MAX` — PHP ints are signed, so the top bit of the hash
  would otherwise produce a negative index.

Things that are easy to get wrong:

- **Dictionary names are string keys that must match a filename.** Adding a dictionary means creating
  `src/dictionaries/<name>.php` *and* adding a `DICTIONARY_*` const *and* listing it in
  `AVAILABLE_DICTIONARIES` — `pools()` rejects anything not in that constant and derives the file path
  straight from the name.
- **Dictionaries must stay lowercase and duplicate-free.** Duplicates shrink the space and break the
  no-repeated-word rule (`English` and `english` would not compare equal); a test enforces this.
- `getSpaceSize()` guards every multiplication against `PHP_INT_MAX` — with the default pools, seven
  positions already overflow.
- The `MAX_SKIPS` and `N == 1` error branches cannot be reached with the shipped dictionaries; they guard
  custom ones.
- The default space is 3.1·10¹² names, so ~50 million entities still collide about 400 times. Callers are
  expected to catch a unique-index violation and retry with `attempt + 1`.
- Walking the sequence is linear in `attempt`, which is why tests cap attempt loops in the low thousands.

## Tests

`tests/GeneratorTest.php` is the whole suite. Tests are discovered through the `/** @test */` annotation
(not `test` prefixes, not PHP attributes) — follow that convention for new cases. `phpunit.xml` sets
`failOnRisky`/`failOnWarning` and restricts deprecations/notices, so warnings fail the build.
