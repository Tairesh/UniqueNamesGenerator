<?php

namespace Chypriote\UniqueNames\Tests;

use Chypriote\UniqueNames\Generator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GeneratorTest extends TestCase
{

    /** @test */
    public function dictionaries_can_be_set()
    {
        $generator = new Generator();
        $dictionaries = ['adjectives', 'animals', 'colors'];
        $generator->setDictionaries($dictionaries);

        $this->assertSame($dictionaries, $generator->getDictionaries());
    }

    /** @test */
    public function dictionaries_can_be_added()
    {
        $generator = new Generator();
        $generator->setDictionaries([]);

        $generator->addDictionary('colors');
        $this->assertSame(['colors'], $generator->getDictionaries());

        $generator->addDictionary('animals');
        $this->assertSame(['colors', 'animals'], $generator->getDictionaries());
    }

    /** @test */
    public function generator_can_use_a_custom_separator()
    {
        $generator = new Generator();
        $generator
            ->setDictionaries(['colors', 'animals'])
            ->setSeparator('-');

        $parts = explode('-', $generator->generate());

        $this->assertCount(2, $parts);
        $this->assertNotEmpty($parts[0]);
        $this->assertNotEmpty($parts[1]);
    }







    /** @test */
    public function dictionaries_contain_no_duplicates_and_no_blanks()
    {
        foreach (Generator::AVAILABLE_DICTIONARIES as $dictionary) {
            $path = __DIR__.'/../src/dictionaries/'.$dictionary.'.php';
            $this->assertFileExists($path);

            $words = include $path;
            $this->assertIsArray($words);
            $this->assertNotEmpty($words);
            $this->assertContainsOnly('string', $words);
            $this->assertNotContains('', $words);
            $this->assertSame(
                array_values(array_unique($words)),
                array_values($words),
                sprintf('Dictionary %s contains duplicates', $dictionary)
            );
        }
    }

    /** @test */
    public function removed_dictionaries_are_gone()
    {
        $this->assertNotContains('countries', Generator::AVAILABLE_DICTIONARIES);
        $this->assertNotContains('star-wars', Generator::AVAILABLE_DICTIONARIES);
        $this->assertFileDoesNotExist(__DIR__.'/../src/dictionaries/countries.php');
        $this->assertFileDoesNotExist(__DIR__.'/../src/dictionaries/star-wars.php');
    }

    /** @test */
    public function generator_requires_at_least_one_dictionary()
    {
        $generator = new Generator();
        $generator->setDictionaries([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot find any dictionary');

        $generator->generate('key');
    }



    /** @test */
    public function generator_rejects_unknown_dictionaries()
    {
        $generator = new Generator();
        $generator->setDictionaries(['colors', 'unknown']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The dictionary unknown could not be found');

        $generator->generate('key');
    }

    /** @test */
    public function space_size_multiplies_position_sizes()
    {
        $generator = new Generator();
        $generator->setDictionaries([['colors'], ['animals']]);

        $this->assertSame(52 * 355, $generator->getSpaceSize());
    }

    /** @test */
    public function a_position_may_merge_several_dictionaries()
    {
        $generator = new Generator();
        $generator->setDictionaries([['adjectives', 'languages', 'colors']]);

        $this->assertSame(1328, $generator->getSpaceSize());
    }

    /** @test */
    public function default_configuration_spans_three_trillion_names()
    {
        $this->assertSame(1328 * 1328 * 355 * 4940, (new Generator())->getSpaceSize());
    }

    /** @test */
    public function space_size_rejects_overflow()
    {
        $generator = new Generator();
        $generator->setDictionaries(array_fill(0, 8, ['adjectives', 'languages', 'colors']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name space exceeds');

        $generator->getSpaceSize();
    }

    /** @test */
    public function generator_rejects_an_empty_position()
    {
        $generator = new Generator();
        $generator->setDictionaries([[]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty position');

        $generator->getSpaceSize();
    }

    /** @test */
    public function seed_and_shuffle_are_gone()
    {
        $this->assertFalse(method_exists(Generator::class, 'setSeed'));
        $this->assertFalse(method_exists(Generator::class, 'setSeedFromString'));
        $this->assertFalse(method_exists(Generator::class, 'setShuffle'));
        $this->assertFalse(method_exists(Generator::class, 'setLength'));
    }

    /** @test */
    public function the_same_key_always_gives_the_same_name()
    {
        $id = '018f4e2a-7c3b-7000-8000-000000000001';

        $first = (new Generator())->generate($id);
        $second = (new Generator())->generate($id);

        $this->assertSame($first, $second);
    }

    /** @test */
    public function integer_and_string_keys_are_different()
    {
        $generator = new Generator();

        $this->assertNotSame($generator->generate(42), $generator->generate('42'));
    }

    /** @test */
    public function a_null_key_gives_different_names()
    {
        $generator = new Generator();

        $names = [];
        for ($i = 0; $i < 1000; $i++) {
            $names[] = $generator->generate();
        }

        $this->assertGreaterThan(995, count(array_unique($names)));
    }

    /** @test */
    public function every_word_comes_from_its_own_position()
    {
        $generator = new Generator();
        $generator
            ->setDictionaries([['colors'], ['animals']])
            ->setSeparator('-');

        [$color, $animal] = explode('-', $generator->generate('some-key'));

        $this->assertContains(lcfirst($color), include __DIR__.'/../src/dictionaries/colors.php');
        $this->assertContains(lcfirst($animal), include __DIR__.'/../src/dictionaries/animals.php');
    }

    /** @test */
    public function attempts_never_repeat_a_name()
    {
        $generator = new Generator();

        $names = [];
        for ($attempt = 0; $attempt <= 1000; $attempt++) {
            $names[] = $generator->generate('collision-key', $attempt);
        }

        $this->assertCount(1001, array_unique($names));
    }

    /** @test */
    public function the_same_attempt_is_reproducible()
    {
        $first = (new Generator())->generate('key', 7);
        $second = (new Generator())->generate('key', 7);

        $this->assertSame($first, $second);
    }

    /** @test */
    public function generator_rejects_a_negative_attempt()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempt cannot be negative');

        (new Generator())->generate('key', -1);
    }

    /** @test */
    public function generator_rejects_an_attempt_above_the_limit()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Attempt is limited to');

        (new Generator())->generate('key', 10001);
    }

    /** @test */
    public function attempts_stop_when_the_space_is_exhausted()
    {
        $generator = new Generator();
        $generator->setDictionaries([['colors']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name space is exhausted');

        $generator->generate('key', 60);
    }

    /** @test */
    public function a_name_never_repeats_a_word()
    {
        $generator = new Generator();
        $generator
            ->setDictionaries([['colors'], ['colors'], ['animals']])
            ->setSeparator('-');

        for ($attempt = 0; $attempt <= 500; $attempt++) {
            $words = explode('-', $generator->generate('repeat-key', $attempt));

            $this->assertCount(3, $words);
            $this->assertSame($words, array_unique($words));
        }
    }

    /** @test */
    public function attempts_stay_unique_with_the_repeat_filter_on()
    {
        $generator = new Generator();
        $generator->setDictionaries([['colors'], ['colors']]);

        $names = [];
        for ($attempt = 0; $attempt <= 500; $attempt++) {
            $names[] = $generator->generate('key', $attempt);
        }

        $this->assertCount(501, array_unique($names));
    }

    /** @test */
    public function every_attempt_maps_to_a_distinct_point_of_the_space()
    {
        $generator = new Generator();
        $generator
            ->setDictionaries([['colors'], ['animals']])
            ->setSeparator('-');

        $this->assertSame(18460, $generator->getSpaceSize());

        $names = [];
        for ($attempt = 0; $attempt <= 2000; $attempt++) {
            $names[] = $generator->generate('bijection-key', $attempt);
        }

        $this->assertCount(2001, array_unique($names));
    }
}
