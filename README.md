# Unique Names Generator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tairesh/unique-names-generator.svg?style=flat-square)](https://packagist.org/packages/tairesh/unique-names-generator)
[![CI](https://github.com/Tairesh/UniqueNamesGenerator/actions/workflows/ci.yml/badge.svg)](https://github.com/Tairesh/UniqueNamesGenerator/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/Tairesh/UniqueNamesGenerator)](https://github.com/Tairesh/UniqueNamesGenerator/blob/main/LICENSE.md)
[![Packagist Downloads](https://img.shields.io/packagist/dm/tairesh/unique-names-generator)](https://packagist.org/packages/tairesh/unique-names-generator)

A package to create readable, meaningful names from a key, deterministically.
> 3.1 trillion name combinations out of the box

This is a fork of [chypriote/UniqueNamesGenerator](https://github.com/chypriote/UniqueNamesGenerator), substantially reworked. The API is not compatible with the original.

## Why this fork exists

The original package draws a random word from each dictionary on every call. On its default `adjectives + animals` pair that is 427,420 distinct names — enough for a demo, not for a dataset. The birthday problem bites early: a 50% chance of a collision arrives at 692 entities, and by 50 million the space is exhausted many times over, with roughly 117 entities sharing every possible name. Retrying a collision does not help either, because a retry is just another random draw that may land on the same name again.

We needed names for tens of millions of entities, each derived from an identifier we already had, and we did not want a hash or a counter glued to the end of the name.

So this fork replaces random generation with a deterministic mapping:

* **A name is a function of a key.** `generate($uuid)` hashes the key and picks a point in the name space. The same key always gives the same name, and the key can be a UUID of any version, a ULID, an integer id, or any other string.
* **Retries are guaranteed to differ.** `generate($uuid, $attempt)` walks a full-cycle sequence through the space, so two attempts on one key can never return the same name. A collision on a unique index is resolved by incrementing `$attempt`, not by rolling the dice again.
* **The space is large enough to matter.** Positions can merge several dictionaries into one pool, and the default configuration spans 3.1 trillion names instead of 427 thousand — about 400 expected collisions across 50 million entities.
* **The dictionaries were cleaned.** The original files carried around 260 duplicate entries, which both shrank the space and skewed the distribution; they were also inconsistently cased. Every dictionary is now lowercase and duplicate-free. `countries` and `star-wars` were dropped, since their multi-word entries do not fit names built by concatenation.

If you want random throwaway names and a small dependency, the original package is the better choice. This fork is for assigning stable names to a lot of rows.


## Installation

Just require the package through composer:

``` bash
composer require tairesh/unique-names-generator
```

## Usage

Pass any key — a UUID of any version, a ULID, an integer id, or an arbitrary string — and get a name back. The same key always yields the same name:

``` php
$generator = new Generator();

echo $generator->generate('018f4e2a-7c3b-7000-8000-000000000001'); // --> TotalSecureMothDorris
echo $generator->generate('018f4e2a-7c3b-7000-8000-000000000002'); // --> CrazyRelievedRoosterRebe
echo $generator->generate(42);                                     // --> DistinctiveStiffStoatMurial
```

The key is hashed as-is, so its format does not matter. The type is part of the key: `generate(42)` and `generate('42')` are different keys.

Called without a key, the generator picks a random one for you:

``` php
echo $generator->generate(); // --> a different name on every call
```

### Resolving collisions

The default space holds 3.1 trillion names, so with 50 million entities you should expect roughly 400 collisions. Pass an attempt number to get a different name for the same key — attempts are guaranteed to never repeat a name:

``` php
$attempt = 0;

while (true) {
    try {
        $entity->name = $generator->generate($entity->uuid, $attempt);
        $entity->save(); // unique index on `name`
        break;
    } catch (UniqueConstraintViolationException) {
        $attempt++;
    }
}
```

``` php
echo $generator->generate('some-uuid', 0); // --> TotalSecureMothDorris
echo $generator->generate('some-uuid', 1); // --> ToughOriginalCuckooJuline
```

`attempt` is capped at `Generator::MAX_ATTEMPT` (10 000); beyond that, and when the space runs out, a `RuntimeException` is thrown.

### Configuring the name

A name is a list of positions. A position is either one dictionary or several dictionaries merged into a single pool:

``` php
$generator = new Generator();
$generator->setDictionaries([['colors'], 'animals']);

echo $generator->generate('a'); // --> PeachMeerkat
echo $generator->generate('b'); // --> MoccasinFrog
```

The default configuration is two positions drawn from a merged `adjectives + languages + colors` pool, then `animals`, then `names`. The number of positions is the length of the name — there is no separate length setting.

Available dictionaries are:
* **adjectives**: 1204 adjectives (default)
* **animals**: 355 animals (default)
* **colors**: 52 colors (default, merged into the first two positions)
* **languages**: 97 languages (default, merged into the first two positions)
* **names**: 4940 given names (default)

`getSpaceSize()` reports how many names the current configuration can produce:

``` php
echo (new Generator())->getSpaceSize();                              // --> 3092797260800
echo (new Generator())->setDictionaries([['colors'], 'animals'])->getSpaceSize(); // --> 18460
```

A word never repeats inside a name, even when two positions share a pool.

You can separate the words:

``` php
$generator = new Generator();
$generator->setDictionaries([['colors'], 'animals'])->setSeparator('-');

echo $generator->generate('a'); // --> Peach-Meerkat
```


## Testing

The test suite runs in Docker, so nothing has to be installed locally beyond Docker and [just](https://github.com/casey/just):

``` bash
just                                           # full suite
just filter testANameNeverRepeatsAWord         # a single test
just check                                     # PHP CS Fixer, PHPStan, PHPMD and the full suite
```

Every recipe wraps `docker compose run --rm tests`, which in turn runs the matching `composer` script
(`test`, `fixer`, `phpstan`, `phpmd`). With PHP and Composer installed on the host you can run those
scripts directly instead.

The container runs PHP 8.1, the lowest supported version. CI runs the suite on 8.1, 8.2, 8.3, 8.4 and 8.5.


## TODO-list

* Possibility to add custom dictionaries


## FAQ

**Q: Can this package do *feature*?**

A: This fork was made out of a need for a personal project. If you have some suggestions feel free to open an issue
or a PR!

**Q: Why not just append a short hash or a counter to a random name?**

A: That is a perfectly good solution, and if it fits your product, use it. We wanted the name itself to be the
identifier, with nothing appended to it. 
