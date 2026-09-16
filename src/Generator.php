<?php

namespace Chypriote\UniqueNames;

use InvalidArgumentException;
use RuntimeException;

class Generator
{
    public const DICTIONARY_ADJECTIVES = 'adjectives';
    public const DICTIONARY_ANIMALS = 'animals';
    public const DICTIONARY_COLORS = 'colors';
    public const DICTIONARY_NAMES = 'names';
    public const DICTIONARY_LANGUAGES = 'languages';

    public const MAX_ATTEMPT = 10000;

    public const MAX_SKIPS = 1000;

    public const AVAILABLE_DICTIONARIES = [
        self::DICTIONARY_ADJECTIVES,
        self::DICTIONARY_ANIMALS,
        self::DICTIONARY_COLORS,
        self::DICTIONARY_NAMES,
        self::DICTIONARY_LANGUAGES,
    ];

    private array $dictionaries = [
        ['adjectives', 'languages', 'colors'],
        ['adjectives', 'languages', 'colors'],
        'animals',
        'names',
    ];

    private ?array $pools = null;

    private ?string $separator = null;

    public function generate(int|string|null $id = null, int $attempt = 0): string
    {
        if ($attempt < 0) {
            throw new InvalidArgumentException('Attempt cannot be negative.');
        }

        if ($attempt > self::MAX_ATTEMPT) {
            throw new RuntimeException(sprintf('Attempt is limited to %d.', self::MAX_ATTEMPT));
        }

        $pools = $this->pools();
        $size = $this->getSpaceSize();

        $hash = hash('sha256', $this->key($id), true);
        $index = $this->readIndex(substr($hash, 0, 8)) % $size;

        if ($size === 1) {
            $name = $this->compose($pools, 0);

            if ($name === null) {
                throw new RuntimeException('Cannot build a name without repeating a word.');
            }

            if ($attempt > 0) {
                throw new RuntimeException('The name space is exhausted: it holds a single name.');
            }

            return $name;
        }

        $step = $this->step(substr($hash, 8, 8), $size);
        $found = 0;
        $skipped = 0;

        for ($visited = 0; $visited < $size; $visited++) {
            $name = $this->compose($pools, $index);

            if ($name !== null) {
                if ($found === $attempt) {
                    return $name;
                }

                $found++;
                $skipped = 0;
            } elseif (++$skipped > self::MAX_SKIPS) {
                throw new RuntimeException('Cannot build a name without repeating a word.');
            }

            $index = ($index + $step) % $size;
        }

        throw new RuntimeException(
            sprintf('The name space is exhausted: it holds fewer than %d usable names.', $attempt + 1)
        );
    }

    private function step(string $bytes, int $size): int
    {
        $step = 1 + ($this->readIndex($bytes) % ($size - 1));

        while ($this->gcd($step, $size) !== 1) {
            $step = $step + 1 >= $size ? 1 : $step + 1;
        }

        return $step;
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    private function key(int|string|null $id): string
    {
        if ($id === null) {
            return 'r:'.random_bytes(16);
        }

        return (is_int($id) ? 'i:' : 's:').$id;
    }

    private function readIndex(string $bytes): int
    {
        return unpack('J', $bytes)[1] & PHP_INT_MAX;
    }

    private function compose(array $pools, int $index): ?string
    {
        $words = [];
        $seen = [];
        $rest = $index;

        foreach ($pools as $pool) {
            $count = count($pool);
            $word = $pool[$rest % $count];

            if (isset($seen[$word])) {
                return null;
            }

            $seen[$word] = true;
            $words[] = ucfirst($word);
            $rest = intdiv($rest, $count);
        }

        return implode((string) $this->separator, $words);
    }

    private function pools(): array
    {
        if ($this->pools !== null) {
            return $this->pools;
        }

        if (!$this->dictionaries) {
            throw new RuntimeException('Cannot find any dictionary. Please provide at least one position.');
        }

        $pools = [];

        foreach ($this->dictionaries as $position) {
            $names = is_array($position) ? $position : [$position];

            if (!$names) {
                throw new RuntimeException('Cannot build a name from an empty position.');
            }

            $words = [];

            foreach ($names as $name) {
                if (!in_array($name, self::AVAILABLE_DICTIONARIES, true)) {
                    throw new RuntimeException(
                        sprintf(
                            'The dictionary %s could not be found. Available dictionaries: %s',
                            $name,
                            implode(', ', self::AVAILABLE_DICTIONARIES)
                        )
                    );
                }

                $words = array_merge($words, include __DIR__.'/dictionaries/'.$name.'.php');
            }

            $pools[] = array_values(array_unique($words));
        }

        return $this->pools = $pools;
    }

    public function getSpaceSize(): int
    {
        $size = 1;

        foreach ($this->pools() as $position => $pool) {
            $count = count($pool);

            if ($size > intdiv(PHP_INT_MAX, $count)) {
                throw new RuntimeException(
                    sprintf('The name space exceeds PHP_INT_MAX at position %d. Use fewer positions.', $position + 1)
                );
            }

            $size *= $count;
        }

        return $size;
    }

    public function getDictionaries(): array
    {
        return $this->dictionaries;
    }

    public function setDictionaries(array $dictionaries): self
    {
        $this->dictionaries = $dictionaries;
        $this->pools = null;

        return $this;
    }

    public function addDictionary(string $dictionary): self
    {
        $this->dictionaries[] = $dictionary;
        $this->pools = null;

        return $this;
    }

    public function setSeparator(?string $separator): self
    {
        $this->separator = $separator;

        return $this;
    }
}
