// Canonical backend keys the Studio translates dynamically. These mirror PHP sources of truth:
//   OPERATION_STAT_KEYS       -> src/Enum/OperationStatus.php cases
//   BUILTIN_HEALTH_CHECK_KEYS -> src/Health/Check/*::name()
// Drift is caught on the PHP side by FrontendStatusContractTest and on the TS side by the
// translations contract test, so every backend value stays localized in both locales.

export const OPERATION_STAT_KEYS = [
  'pending',
  'in_progress',
  'recovery_required',
  'completed',
  'completed_with_observer_error',
  'failed',
  'skipped',
] as const

export const BUILTIN_HEALTH_CHECK_KEYS = [
  'async_transport',
  'database_schema',
  'dependency_tracking',
  'operation_journal',
  'operation_run_backlog',
  'rule_config',
  'shared_cache',
] as const
