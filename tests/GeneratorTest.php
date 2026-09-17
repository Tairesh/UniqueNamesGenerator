<?php

namespace Tairesh\UniqueNames\Tests;

use PHPUnit\Framework\TestCase;
use Tairesh\UniqueNames\Generator;

class GeneratorTest extends TestCase
{
    public function testDictionariesCanBeSet(): void
    {
        $generator = new Generator();
        $dictionaries = ['adjectives', 'animals', 'colors'];
        $generator->setDictionaries($dictionaries);

        self::assertSame($dictionaries, $generator->getDictionaries());
    }

    public function testDictionariesCanBeAdded(): void
    {
        $generator = new Generator();
        $generator->setDictionaries([]);

        $generator->addDictionary('colors');
        self::assertSame(['colors'], $generator->getDictionaries());

        $generator->addDictionary('animals');
        self::assertSame(['colors', 'animals'], $generator->getDictionaries());
    }

    public function testGeneratorCanUseACustomSeparator(): void
    {
        $generator = new Generator();
        $generator
            ->setDictionaries(['colors', 'animals'])
            ->setSeparator('-');

        $parts = explode('-', $generator->generate());

        self::assertCount(2, $parts);
        self::assertNotEmpty($parts[0]);
        self::assertNotEmpty($parts[1]);
    }

    public function testDictionariesContainNoDuplicatesAndNoBlanks(): void
    {
        foreach (Generator::AVAILABLE_DICTIONARIES as $dictionary) {
            $path = __DIR__.'/../src/dictionaries/'.$dictionary.'.php';
            self::assertFileExists($path);

            $words = include $path;
            self::assertIsArray($words);
            self::assertNotEmpty($words);
            self::assertContainsOnly('string', $words);
            self::assertNotContains('', $words);

            /** @var list<string> $strings */
            $strings = array_values($words);

            self::assertSame(
                array_unique($strings),
                $strings,
                sprintf('Dictionary %s contains duplicates', $dictionary)
            );
        }
    }

    public function testRemovedDictionariesAreGone(): void
    {
        self::assertNotContains('countries', Generator::AVAILABLE_DICTIONARIES);
        self::assertNotContains('star-wars', Generator::AVAILABLE_DICTIONARIES);
        self::assertFileDoesNotExist(__DIR__.'/../src/dictionaries/countries.php');
        self::assertFileDoesNotExist(__DIR__.'/../src/dictionaries/star-wars.php');
    }

    public function testGeneratorRequiresAtLeastOneDictionary(): void
    {
        $generator = new Generator();
        $generator->setDictionaries([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot find any dictionary');

        $generator->generate('key');
    }

    public function testGeneratorRejectsUnknownDictionaries(): void
    {
        $generator = new Generator();
        $generator->setDictionaries(['colors', 'unknown']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The dictionary unknown could not be found');

        $generator->generate('key');
    }

    public function testSpaceSizeMultipliesPositionSizes(): void
    {
        $colors = (new Generator())->setDictionaries([['colors']])->getSpaceSize();
        $animals = (new Generator())->setDictionaries([['animals']])->getSpaceSize();

        $generator = new Generator();
        $generator->setDictionaries([['colors'], ['animals']]);

        self::assertSame($colors * $animals, $generator->getSpaceSize());
    }

    public function testAPositionMayMergeSeveralDictionaries(): void
    {
        $adjectives = (new Generator())->setDictionaries([['adjectives']])->getSpaceSize();
        $languages = (new Generator())->setDictionaries([['languages']])->getSpaceSize();
        $colors = (new Generator())->setDictionaries([['colors']])->getSpaceSize();

        $merged = (new Generator())
            ->setDictionaries([['adjectives', 'languages', 'colors']])
            ->getSpaceSize();

        self::assertGreaterThan($adjectives, $merged);
        self::assertGreaterThan($languages, $merged);
        self::assertGreaterThan($colors, $merged);

        // the union is deduplicated: the dictionaries share words such as `gold` and `orange`
        self::assertLessThan($adjectives + $languages + $colors, $merged);
    }

    public function testDefaultConfigurationSpansTrillionsOfNames(): void
    {
        self::assertGreaterThan(3_000_000_000_000, (new Generator())->getSpaceSize());
    }

    public function testSpaceSizeRejectsOverflow(): void
    {
        $generator = new Generator();
        $generator->setDictionaries(array_fill(0, 8, ['adjectives', 'languages', 'colors']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('name space exceeds');

        $generator->getSpaceSize();
    }

    public function testGeneratorRejectsAnEmptyPosition(): void
    {
        $generator = new Generator();
        $generator->setDictionaries([[]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty position');

        $generator->getSpaceSize();
    }

    public function testSeedAndShuffleAreGone(): void
    {
        self::assertFalse(method_exists(Generator::class, 'setSeed'));
        self::assertFalse(method_exists(Generator::class, 'setSeedFromString'));
        self::assertFalse(method_exists(Generator::class, 'setShuffle'));
        self::assertFalse(method_exists(Generator::class, 'setLength'));
    }

    public function testTheSameKeyAlwaysGivesTheSameName(): void
    {
        $id = '018f4e2a-7c3b-7000-8000-000000000001';

        $first = (new Generator())->generate($id);
        $second = (new Generator())->generate($id);

        self::assertSame($first, $second);
    }

    public function testIntegerAndStringKeysAreDifferent(): void
    {
        $generator = new Generator();

        self::assertNotSame($generator->generate(42), $generator->generate('42'));
    }

    public function testANullKeyGivesDifferentNames(): void
    {
        $generator = new Generator();

        $names = [];
        for ($i = 0; $i < 1000; ++$i) {
            $names[] = $generator->generate();
        }

        self::assertGreaterThan(995, count(array_unique($names)));
    }

    public function testEveryWordComesFromItsOwnPosition(): void
    {
        $generator = new Generator();
        $generator
            ->setDictionaries([['colors'], ['animals']])
            ->setSeparator('-');

        [$color, $animal] = explode('-', $generator->generate('some-key'));

        /** @var list<string> $colors */
        $colors = include __DIR__.'/../src/dictionaries/colors.php';
        /** @var list<string> $animals */
        $animals = include __DIR__.'/../src/dictionaries/animals.php';

        self::assertContains(lcfirst($color), $colors);
        self::assertContains(lcfirst($animal), $animals);
    }

    public function testAttemptsNeverRepeatAName(): void
    {
        $generator = new Generator();

        $names = [];
        for ($attempt = 0; $attempt <= 1000; ++$attempt) {
            $names[] = $generator->generate('collision-key', $attempt);
        }

        self::assertCount(1001, array_unique($names));
    }

    public function testTheSameAttemptIsReproducible(): void
    {
        $first = (new Generator())->generate('key', 7);
        $second = (new Generator())->generate('key', 7);

        self::assertSame($first, $second);
    }

    public function testGeneratorRejectsANegativeAttempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempt cannot be negative');

        (new Generator())->generate('key', -1);
    }

    public function testGeneratorRejectsAnAttemptAboveTheLimit(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attempt is limited to');

        (new Generator())->generate('key', 10001);
    }

    public function testAttemptsStopWhenTheSpaceIsExhausted(): void
    {
        $generator = new Generator();
        $generator->setDictionaries([['colors']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('name space is exhausted');

        $generator->generate('key', 60);
    }

    public function testANameNeverRepeatsAWord(): void
    {
        $generator = new Generator();
        $generator
            ->setDictionaries([['colors'], ['colors'], ['animals']])
            ->setSeparator('-');

        for ($attempt = 0; $attempt <= 500; ++$attempt) {
            $words = explode('-', $generator->generate('repeat-key', $attempt));

            self::assertCount(3, $words);
            self::assertSame($words, array_unique($words));
        }
    }

    public function testAttemptsStayUniqueWithTheRepeatFilterOn(): void
    {
        $generator = new Generator();
        $generator->setDictionaries([['colors'], ['colors']]);

        $names = [];
        for ($attempt = 0; $attempt <= 500; ++$attempt) {
            $names[] = $generator->generate('key', $attempt);
        }

        self::assertCount(501, array_unique($names));
    }

    public function testEveryAttemptMapsToADistinctPointOfTheSpace(): void
    {
        $generator = new Generator();
        $generator
            ->setDictionaries([['colors'], ['animals']])
            ->setSeparator('-');

        self::assertGreaterThan(2000, $generator->getSpaceSize());

        $names = [];
        for ($attempt = 0; $attempt <= 2000; ++$attempt) {
            $names[] = $generator->generate('bijection-key', $attempt);
        }

        self::assertCount(2001, array_unique($names));
    }
}
