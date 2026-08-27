const js = require('@eslint/js');
const globals = require('globals');

/**
 * Lint config for the module's hand written front end JavaScript and the
 * Playwright suite. There is no bundler: files in `views/js/` are served to the
 * browser exactly as they are committed, so this is the only gate on them.
 */
module.exports = [
    {
        ignores: [
            'vendor/**',
            'node_modules/**',
            'build/**',
            // Third party, vendored as-is.
            'views/js/jquery.json-viewer.js',
            'tests/playwright/playwright-report/**',
            'tests/playwright/test-results/**',
        ],
    },
    js.configs.recommended,
    {
        // Storefront and back office scripts, loaded directly by the browser.
        files: ['views/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                ...globals.jquery,
                // Injected by PrestaShop and by the module's own templates.
                prestashop: 'readonly',
                monei: 'readonly',
                OPC: 'readonly',
                // Defined by the inline scripts in
                // views/templates/hook/displayPaymentByBinaries.tpl. They are
                // genuinely globals today; extracting that template's JavaScript
                // is what will let these be declared in a file instead.
                initMoneiApplePay: 'readonly',
                initMoneiBizum: 'readonly',
                initMoneiCard: 'readonly',
                initMoneiGooglePay: 'readonly',
                initMoneiPayPal: 'readonly',
                MoneiVars: 'readonly',
                showErrorMessage: 'readonly',
                showSuccessMessage: 'readonly',
            },
        },
        rules: {
            eqeqeq: ['error', 'smart'],
            'no-var': 'error',
            'prefer-const': 'error',
            // Callback parameters must keep their position even when unused, and a
            // catch binding is frequently only there to branch on failure.
            'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none' }],
        },
    },
    {
        // Node side: the Playwright suite and its fixtures.
        files: ['tests/**/*.js', 'eslint.config.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'commonjs',
            globals: { ...globals.node },
        },
        rules: {
            eqeqeq: ['error', 'smart'],
            'no-var': 'error',
            'prefer-const': 'error',
            'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none' }],
            // The suite reports environment facts a developer needs to see.
            'no-console': 'off',
        },
    },
];
