<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('vendor')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'php_unit_test_annotation' => ['style' => 'prefix'],
    ])
    ->setFinder($finder)
;
