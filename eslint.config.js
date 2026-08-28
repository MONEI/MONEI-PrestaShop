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
                // Provided by Media::addJsDef in monei.php. The theme prints them
                // after the external scripts, so they are only safe to read from
                // inside a function — see the note at the top of payment.js.
                moneiAccountId: 'readonly',
                moneiAmount: 'readonly',
                moneiBizumStyle: 'readonly',
                moneiCardHolderNameNotValid: 'readonly',
                moneiCardLayout: 'readonly',
                moneiCardInputStyle: 'readonly',
                moneiCreatePaymentUrlController: 'readonly',
                moneiCurrency: 'readonly',
                moneiErrorOccurred: 'readonly',
                moneiErrorOccurredWithPayPal: 'readonly',
                moneiPaymentAction: 'readonly',
                moneiPaymentCreationFailed: 'readonly',
                moneiPaymentProcessed: 'readonly',
                moneiPaymentRequestStyle: 'readonly',
                moneiPayPalStyle: 'readonly',
                moneiProcessing: 'readonly',
                moneiProcessingPayment: 'readonly',
                moneiToken: 'readonly',
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
        // front.js calls the init functions by name; payment.js declares them.
        // Declaring them globally for both files would make payment.js redeclare
        // its own functions.
        files: ['views/js/front/front.js'],
        languageOptions: {
            globals: {
                AppOPC: 'readonly',
                initMoneiApplePay: 'readonly',
                initMoneiBizum: 'readonly',
                initMoneiCard: 'readonly',
                initMoneiGooglePay: 'readonly',
                initMoneiPayPal: 'readonly',
            },
        },
    },
    {
        // The init functions are this file's public surface, consumed by front.js.
        files: ['views/js/front/payment.js'],
        rules: {
            'no-unused-vars': [
                'error',
                { args: 'none', caughtErrors: 'none', varsIgnorePattern: '^initMonei' },
            ],
            // ⚠️ `var` is deliberate here and must stay. At the top level of a
            // classic script `var` also creates a property on `window`, while
            // `const` does not. This file was extracted verbatim from inline
            // template scripts, and third party one page checkout modules reach
            // these helpers by name, so narrowing them to lexical bindings would
            // be a behaviour change smuggled in under a refactor.
            'no-var': 'off',
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
