import js from '@eslint/js';
import globals from 'globals';

export default [
    {
        ignores: [
            'node_modules/**',
            'vendor/**',
            'public/**',
            'resources/js/vendor/**',
        ],
    },
    js.configs.recommended,
    {
        files: ['resources/js/tables/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.es2024,
            },
        },
        rules: {
            'no-unused-vars': ['error', { argsIgnorePattern: '^_', caughtErrors: 'none' }],
            'no-prototype-builtins': 'off',
            'no-empty': ['error', { allowEmptyCatch: true }],
            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'prefer-const': 'error',
            'no-var': 'error',
            'no-console': 'off',
        },
    },
];
