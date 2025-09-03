<?php
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->notPath([
        'build',
        'config',
        'translations',
        'files',
        'node_modules',
        'vendor',
    ]);

$config = new PhpCsFixer\Config();
$config
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'array_indentation' => true,
        'cast_spaces' => [
            'space' => 'single',
        ],
        'no_unneeded_control_parentheses' => ['statements' => ['break', 'clone', 'continue', 'echo_print', 'return', 'switch_case', 'yield']],
        'blank_line_before_statement' => ['statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try']],
        'operator_linebreak' => ['position' => 'beginning'],
        'combine_consecutive_issets' => true,
        'concat_space' => [
            'spacing' => 'one',
        ],
        'error_suppression' => [
            'mute_deprecation_error' => false,
            'noise_remaining_usages' => false,
            'noise_remaining_usages_exclude' => [],
        ],
        'function_to_constant' => false,
        'method_chaining_indentation' => true,
        'no_alias_functions' => false,
        'no_superfluous_phpdoc_tags' => false,
        'non_printable_character' => [
            'use_escape_sequences_in_strings' => true,
        ],
        'phpdoc_align' => [
            'align' => 'left',
        ],
        'phpdoc_summary' => false,
        'protected_to_private' => false,
        'psr_autoloading' => false,
        'self_accessor' => false,
        'yoda_style' => false,
        'single_line_throw' => false,
        'no_alias_language_construct_call' => false,
        'no_blank_lines_after_phpdoc' => true,
        'blank_line_after_opening_tag' => false,
        'single_blank_line_before_namespace' => false,
        'visibility_required' => [
            'elements' => ['property', 'method'],
        ],
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays'],
            // Don't add trailing commas in function parameters for PHP 7.4 compatibility
            // 'after_heredoc' => false, // PHP 7.3+
        ],
        'no_trailing_comma_in_singleline' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php_cs.cache');

return $config;
