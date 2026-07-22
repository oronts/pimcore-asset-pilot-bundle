import js from '@eslint/js'
import jsxA11y from 'eslint-plugin-jsx-a11y'
import reactHooks from 'eslint-plugin-react-hooks'
import globals from 'globals'
import tseslint from 'typescript-eslint'

export default tseslint.config(
  {
    ignores: ['node_modules/**', 'dist/**', '../../public/studio/build/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['js/**/*.{ts,tsx}', '*.ts'],
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.es2021,
      },
    },
    plugins: {
      'jsx-a11y': jsxA11y,
      'react-hooks': reactHooks,
    },
    rules: {
      ...jsxA11y.flatConfigs.recommended.rules,
      'react-hooks/exhaustive-deps': 'error',
      'react-hooks/rules-of-hooks': 'error',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      'no-empty': ['error', { allowEmptyCatch: true }],
    },
    settings: {
      react: {
        version: '18.3',
      },
    },
  },
  {
    files: ['scripts/**/*.mjs', '*.config.mjs'],
    languageOptions: {
      globals: globals.node,
    },
  },
)
