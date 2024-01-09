<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/scripts',
        __DIR__ . '/src',
        __DIR__ . '/tests/unit',
    ])
    ->append([
        __DIR__ . '/bin/changelog',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        'fully_qualified_strict_types' => true,
        'is_null' => true,
        'native_constant_invocation' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'no_unneeded_import_alias' => true,
        'no_unused_imports' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'single_import_per_statement' => true,
        'yoda_style' => ['equal' => false, 'identical' => false],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
