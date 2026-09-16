# Deterministic Unique Names Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (recommended) or superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить случайный `generate()` детерминированной функцией `generate($id, $attempt)`, которая отображает произвольный ключ в имя из пространства 3,1·10¹² и гарантирует различие имён между попытками.

**Architecture:** Имя — это число `x` в `[0, N)`, разложенное по позициям в смешанной системе счисления. Стартовая позиция и шаг выводятся из sha256 ключа; шаг взаимно прост с `N`, поэтому последовательность `x, x+g, x+2g, …` обходит диапазон без повторов, а `attempt` выбирает i-й допустимый её элемент. Всё живёт в единственном классе `Generator`.

**Tech Stack:** PHP 8.5 (union types, `intdiv`, `hash('sha256', …, true)`, `random_bytes`), PHPUnit 10.5, запуск только через Docker (`just`).

## Global Constraints

- Спека: `docs/superpowers/specs/2026-09-17-deterministic-unique-names-design.md`
- Минимальная версия PHP: `>=8.1` (union types в сигнатуре `generate()`)
- Все команды выполняются только в контейнере: `just`, `just filter <name>`, `just rebuild`. Прямые вызовы `composer`/`vendor/bin/phpunit` на хосте запрещены — PHP на хосте нет.
- Тесты обнаруживаются по аннотации `/** @test */`, имена методов в snake_case — как в существующем `tests/GeneratorTest.php`
- `phpunit.xml` включает `failOnWarning` и `failOnRisky`: любое предупреждение роняет сборку
- Обратная совместимость API не требуется — это новая библиотека по мотивам существующей
- Никаких новых зависимостей в `composer.json` кроме изменения версии PHP

---

### Task 1: Чистка словарей и версия PHP

Удаляем два словаря, убираем дубликаты из оставшихся, поднимаем версию PHP. Всё последующее опирается на то, что в словаре нет повторов.

**Files:**
- Delete: `src/dictionaries/countries.php`, `src/dictionaries/star-wars.php`
- Modify: `src/dictionaries/adjectives.php` (1449 → 1204 строки), `src/dictionaries/animals.php` (377 → 355)
- Modify: `src/Generator.php` (константы `DICTIONARY_COUNTRIES`, `DICTIONARY_STAR_WARS`, `AVAILABLE_DICTIONARIES`)
- Modify: `composer.json` (`"php": ">=8.1"`)
- Test: `tests/GeneratorTest.php`

**Interfaces:**
- Consumes: ничего
- Produces: `Generator::AVAILABLE_DICTIONARIES` = `['adjectives', 'animals', 'colors', 'names', 'languages']`; каждый файл словаря возвращает список уникальных непустых строк в нижнем регистре

- [x] **Step 1: Написать падающий тест на отсутствие дубликатов**

В `tests/GeneratorTest.php` заменить тест `available_dictionaries_have_matching_non_empty_resources` на:

```php
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
```

- [x] **Step 2: Запустить и убедиться, что тесты падают**

Run: `just filter dictionaries_contain_no_duplicates_and_no_blanks`
Expected: FAIL — `Dictionary adjectives contains duplicates`

- [x] **Step 3: Удалить два словаря и их константы**

Удалить файлы `src/dictionaries/countries.php` и `src/dictionaries/star-wars.php`.

В `src/Generator.php` удалить строки с `DICTIONARY_COUNTRIES` и `DICTIONARY_STAR_WARS` и привести константу к виду:

```php
    public const AVAILABLE_DICTIONARIES = [
        self::DICTIONARY_ADJECTIVES,
        self::DICTIONARY_ANIMALS,
        self::DICTIONARY_COLORS,
        self::DICTIONARY_NAMES,
        self::DICTIONARY_LANGUAGES,
    ];
```

- [x] **Step 4: Дедуплицировать adjectives и animals**

> Выполнено шире, чем написано: словари приводятся к нижнему регистру целиком.
> `languages` и `names` хранились с заглавной буквы, поэтому `english`/`English` не
> схлопывались в пуле (1341 вместо 1328) и не распознавались как повтор слова.

Переписать оба файла, сохранив порядок первого вхождения каждого слова. Одноразовый скрипт (запускать из корня проекта, он меняет файлы на месте):

```bash
docker compose run --rm tests php -r '
foreach (["adjectives", "animals"] as $name) {
    $path = "/app/src/dictionaries/$name.php";
    $words = array_values(array_unique(array_map("strtolower", (include $path))));
    $body = implode("", array_map(fn ($w) => "    " . var_export($w, true) . ",\n", $words));
    file_put_contents($path, "<?php\n\nreturn [\n{$body}];\n");
    printf("%s: %d words\n", $name, count($words));
}'
```

Ожидаемый вывод: `adjectives: 1204 words`, `animals: 355 words`.

- [x] **Step 5: Поднять версию PHP в composer.json**

В `composer.json` заменить `"php": ">=7.4"` на `"php": ">=8.1"`.

- [x] **Step 6: Пересобрать образ и прогнать тесты**

Run: `just rebuild && just`
Expected: PASS — изменение `composer.json` требует именно `just rebuild`, иначе volume сохранит старые зависимости.

---

### Task 2: Позиции, пулы и размер пространства

Позиция имени становится либо строкой-словарём, либо массивом словарей, объединяемых в пул. Появляется `getSpaceSize()` с защитой от переполнения. Уходят `setLength()`, `setSeed()`, `setSeedFromString()`, `setShuffle()`.

**Files:**
- Modify: `src/Generator.php`
- Test: `tests/GeneratorTest.php`

**Interfaces:**
- Consumes: `Generator::AVAILABLE_DICTIONARIES` из Task 1
- Produces: `setDictionaries(array $dictionaries): self` — элемент либо `string`, либо `string[]`; `getSpaceSize(): int`; приватный `pools(): array` — список списков уникальных слов, по одному на позицию

- [x] **Step 1: Написать падающие тесты**

Удалить из `tests/GeneratorTest.php` тесты `generator_can_generate_names_with_a_custom_length`, `generator_can_generate_a_single_word_name`, `integer_seed_makes_generation_predictable`, `string_seed_makes_generation_predictable`, `generator_can_shuffle_dictionaries`, `generator_rejects_lengths_less_than_one`, `generator_rejects_length_bigger_than_number_of_dictionaries` — они проверяют удаляемый API. Добавить:

```php
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
```

- [x] **Step 2: Запустить и убедиться, что тесты падают**

Run: `just filter space_size_multiplies_position_sizes`
Expected: FAIL — `Call to undefined method … ::getSpaceSize()`

- [x] **Step 3: Переписать конфигурацию в Generator**

Заменить поля и удаляемые методы в `src/Generator.php`. Поля:

```php
    private array $dictionaries = [
        ['adjectives', 'languages', 'colors'],
        ['adjectives', 'languages', 'colors'],
        'animals',
        'names',
    ];

    private ?array $pools = null;

    private ?string $separator = null;
```

Удалить поля `$resources`, `$length`, `$seed`, `$shuffle` и методы `setLength()`, `setSeed()`, `setSeedFromString()`, `setShuffle()`, `loadResources()`, `validateConfig()`.

Сеттеры сбрасывают кэш:

```php
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
```

- [x] **Step 4: Реализовать pools() и getSpaceSize()**

```php
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
```

- [x] **Step 5: Прогнать тесты**

Run: `just`
Expected: PASS для новых тестов Task 2. Тесты, вызывающие старый `generate()`, на этом шаге падают — это ожидаемо, их чинит Task 3.

---

### Task 3: Детерминированное generate($id)

`generate()` перестаёт использовать `mt_rand` и становится функцией от ключа. Пока без `attempt` — он появится в Task 4.

**Files:**
- Modify: `src/Generator.php`
- Test: `tests/GeneratorTest.php`

**Interfaces:**
- Consumes: `pools()`, `getSpaceSize()` из Task 2
- Produces: `generate(int|string|null $id = null): string`; приватные `key(int|string|null $id): string`, `readIndex(string $bytes): int`, `compose(array $pools, int $index): ?string`

- [x] **Step 1: Написать падающие тесты**

Заменить в `tests/GeneratorTest.php` тест `generator_always_gives_string_without_blanks` и добавить остальные:

```php
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
    public function generator_rejects_unknown_dictionaries()
    {
        $generator = new Generator();
        $generator->setDictionaries(['colors', 'unknown']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The dictionary unknown could not be found');

        $generator->generate('key');
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
```

- [x] **Step 2: Запустить и убедиться, что тесты падают**

Run: `just filter the_same_key_always_gives_the_same_name`
Expected: FAIL — старый `generate()` возвращает случайное имя, два вызова не совпадут

- [x] **Step 3: Реализовать generate() и хелперы**

Заменить метод `generate()` в `src/Generator.php`:

```php
    public function generate(int|string|null $id = null): string
    {
        $pools = $this->pools();
        $size = $this->getSpaceSize();

        $hash = hash('sha256', $this->key($id), true);
        $index = $this->readIndex(substr($hash, 0, 8)) % $size;

        return $this->compose($pools, $index);
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

    private function compose(array $pools, int $index): string
    {
        $words = [];
        $rest = $index;

        foreach ($pools as $pool) {
            $count = count($pool);
            $words[] = ucfirst($pool[$rest % $count]);
            $rest = intdiv($rest, $count);
        }

        return implode((string) $this->separator, $words);
    }
```

`unpack('J', …)` читает 8 байт как 64-битное big-endian число; `& PHP_INT_MAX` гасит знаковый бит, оставляя 63 бита.

- [x] **Step 4: Прогнать тесты**

Run: `just`
Expected: PASS

---

### Task 4: Параметр attempt

Разные попытки при одном ключе дают гарантированно разные имена.

**Files:**
- Modify: `src/Generator.php`
- Test: `tests/GeneratorTest.php`

**Interfaces:**
- Consumes: `generate(int|string|null $id)`, `compose()`, `readIndex()` из Task 3
- Produces: `generate(int|string|null $id = null, int $attempt = 0): string`; константы `MAX_ATTEMPT = 10000`; приватные `step(string $bytes, int $size): int`, `gcd(int $a, int $b): int`

- [x] **Step 1: Написать падающие тесты**

```php
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
```

Добавить `use InvalidArgumentException;` в шапку тестового файла.

- [x] **Step 2: Запустить и убедиться, что тесты падают**

Run: `just filter attempts_never_repeat_a_name`
Expected: FAIL — `generate()` принимает один аргумент

- [x] **Step 3: Реализовать обход последовательности**

Добавить константу в `src/Generator.php` и переписать `generate()`:

```php
    public const MAX_ATTEMPT = 10000;
```

```php
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
            if ($attempt > 0) {
                throw new RuntimeException('The name space is exhausted: it holds a single name.');
            }

            return $this->compose($pools, 0);
        }

        $step = $this->step(substr($hash, 8, 8), $size);

        for ($seen = 0; $seen < $attempt; $seen++) {
            $index = ($index + $step) % $size;
        }

        if ($attempt >= $size) {
            throw new RuntimeException(
                sprintf('The name space is exhausted: it holds %d names.', $size)
            );
        }

        return $this->compose($pools, $index);
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
```

Проверка `$attempt >= $size` стоит до `compose()`, чтобы исчерпание пространства было явной ошибкой, а не молчаливым повтором уже выданного имени.

Добавить `use InvalidArgumentException;` в шапку `src/Generator.php`.

- [x] **Step 4: Прогнать тесты**

Run: `just`
Expected: PASS

---

### Task 5: Запрет повторов слов

Имя, в котором слово встречается дважды, пропускается, а попытка выбирает i-й допустимый элемент последовательности.

**Files:**
- Modify: `src/Generator.php`
- Test: `tests/GeneratorTest.php`

**Interfaces:**
- Consumes: `generate()`, `step()`, `compose()` из Task 4
- Produces: `compose(array $pools, int $index): ?string` (возвращает `null` при повторе); константа `MAX_SKIPS = 1000`

- [x] **Step 1: Написать падающие тесты**

```php
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

```

Ветка `MAX_SKIPS` тестом не покрывается сознательно: чтобы фильтр повторов стал неразрешимым, позиций должно быть больше, чем слов в самом маленьком пуле (52 у colors), а 53 позиции переполняют `N` задолго до этого. То же с `N == 1` — однословных словарей в поставке нет. Обе ветки остаются защитой от конфигураций с пользовательскими словарями, а не поведением, которое можно вызвать текущими данными.

- [x] **Step 2: Запустить и убедиться, что тесты падают**

Run: `just filter a_name_never_repeats_a_word`
Expected: FAIL — среди 501 имени найдётся хотя бы одно вида `Amber-Amber-Cat`

- [x] **Step 3: Научить compose() отбраковывать повторы**

```php
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
```

- [x] **Step 4: Переписать обход так, чтобы attempt считал только допустимые значения**

Заменить в `generate()` весь блок от `if ($size === 1)` до завершающего `return $this->compose($pools, $index);` — включая цикл `for ($seen = …)` и проверку `if ($attempt >= $size)`, добавленные в Task 4, — на:

```php
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
```

Добавить константу:

```php
    public const MAX_SKIPS = 1000;
```

Цикл ограничен `$size` шагами, поэтому полный обход диапазона завершается ошибкой исчерпания, а не возвратом уже выданного имени.

- [x] **Step 5: Прогнать тесты**

Run: `just`
Expected: PASS. Тест `attempts_stop_when_the_space_is_exhausted` из Task 4 теперь ловит сообщение `name space is exhausted` из нового места — убедиться, что он всё ещё зелёный.

---

### Task 6: Проверка биективности и документация

Прямая проверка главного свойства на полном малом пространстве плюс приведение README и CLAUDE.md в соответствие.

**Files:**
- Modify: `tests/GeneratorTest.php`, `README.md`, `CLAUDE.md`
- Test: `tests/GeneratorTest.php`

**Interfaces:**
- Consumes: весь публичный API из Task 2–5
- Produces: ничего

- [x] **Step 1: Написать тест биективности**

Пространство `[['colors'], ['animals']]` — это 52 × 355 = 18 460 индексов, из которых два дают повтор слова (`coral` и `salmon` есть и в colors, и в animals), то есть 18 458 допустимых имён.

Перебираем 2001 попытку, а не все 10 001: обход последовательности линеен по `attempt`, поэтому полный прогон до `MAX_ATTEMPT` стоил бы около 50 млн итераций и растянул бы сюиту на минуты. 2001 попытки дают ~2 млн итераций и проверяют ровно то же свойство.

```php
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
```

- [x] **Step 2: Запустить тест**

Run: `just filter every_attempt_maps_to_a_distinct_point_of_the_space`
Expected: PASS — реализация уже готова, тест подтверждает свойство целиком

- [x] **Step 3: Обновить README**

В `README.md`:
- строку `> More than 400,000 name combinations…` заменить на `> 3.1 trillion name combinations out of the box`
- в списке доступных словарей убрать `countries` и `star wars`, отметить дефолт: две позиции из объединённого пула `adjectives + languages + colors`, затем `animals`, затем `names`
- заменить примеры `setLength`, `setShuffle`, `setSeed` на примеры `generate($uuid)` и `generate($uuid, $attempt)`, показав ретрай при нарушении уникального индекса
- показать, что позиция задаётся массивом словарей: `setDictionaries([['adjectives', 'colors'], 'animals'])`

- [x] **Step 4: Обновить CLAUDE.md**

В `CLAUDE.md` секция Architecture описывает удалённое поведение. Заменить пункты про `length`-префикс, глобальный `mt_srand`, мутацию словарей в `setShuffle` и про пробелы в `star-wars`/`countries` на актуальные: позиция = словарь или пул словарей, индекс в смешанной системе счисления, шаг взаимно прост с размером пространства, `attempt` выбирает i-й допустимый элемент, словари без дубликатов. Цифру комбинаций указать 3,1·10¹².

- [x] **Step 5: Прогнать полный набор**

Run: `just`
Expected: PASS — все тесты зелёные
