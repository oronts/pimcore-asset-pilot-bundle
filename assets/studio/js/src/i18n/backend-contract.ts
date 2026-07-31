// Canonical backend keys the Studio translates dynamically. These mirror PHP sources of truth:
//   OPERATION_STAT_KEYS            -> src/Enum/OperationStatus.php cases
//   BUILTIN_HEALTH_CHECK_KEYS      -> src/Health/Check/*::name()
//   DISPOSITION_OUTCOME_KEYS       -> src/Enum/DispositionOutcome.php cases
//   OPERATION_RUN_STATUS_KEYS      -> src/Enum/OperationRunStatus.php cases
//   OPERATION_RUN_ITEM_STATUS_KEYS -> src/Enum/OperationRunItemStatus.php cases
//   UNDO_HEAL_REASON_KEYS          -> src/Enum/UndoHealReason.php cases
//   RECOVERY_KIND_KEYS             -> src/Enum/OperationKind.php cases
//   CONFIDENCE_LEVEL_KEYS          -> src/Enum/ConfidenceLevel.php cases (rendered hyphenated)
//   HEAL_HISTORY_STATUS_KEYS       -> src/Service/IntegrityHealLog.php STATUS_HEALED/STATUS_UNDONE (the two statuses the history query returns)
//   DELIVERY_OUTCOME_KEYS          -> src/Enum/OperationDeliveryOutcome.php cases
//   HEALTH_STATUS_KEYS             -> src/Enum/HealthStatus.php cases
//   HEAL_OUTCOME_KEYS              -> src/Enum/HealOutcome.php cases
//   ACTOR_TYPE_KEYS                -> src/Enum/ActorType.php cases (rendered as audit.actor-<v>)
//   DRIFT_ELIGIBILITY_KEYS         -> src/Enum/DriftEligibility.php cases (rendered as drift.eligibility-<v>)
// OPERATION_STAT_KEYS and RECOVERY_KIND_KEYS each drive a second render prefix too (status.<v> and
// audit.operation-<v>); the translations contract test asserts both prefixes off the one mirror.
// Drift is caught on the PHP side by FrontendTranslationContractTest (each list is compared to its
// enum/source) and on the TS side by the translations contract test, so every backend value stays localized.

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

export const DISPOSITION_OUTCOME_KEYS = [
  'quarantined',
  'deleted',
  'left_referenced',
  'left_error',
  'blocked',
  'skipped',
] as const

export const OPERATION_RUN_STATUS_KEYS = [
  'pending_dispatch',
  'queued',
  'running',
  'cancel_requested',
  'cancelled',
  'completed',
  'blocked',
  'partial',
  'failed',
] as const

export const OPERATION_RUN_ITEM_STATUS_KEYS = [
  'queued',
  'running',
  'completed',
  'blocked',
  'skipped',
  'failed',
  'cancelled',
] as const

export const UNDO_HEAL_REASON_KEYS = [
  'already_undone',
  'superseded',
  'asset_busy',
  'asset_not_found',
  'not_permitted',
  'asset_locked',
  'excluded_folder',
  'no_reversible_heal',
  'version_missing',
  'asset_changed',
  'restore_failed',
  'log_update_failed',
] as const

export const RECOVERY_KIND_KEYS = [
  'move',
  'revert',
] as const

export const CONFIDENCE_LEVEL_KEYS = [
  'protected',
  'historically_used',
  'recently_uploaded',
  'probably_unused',
  'definitely_unused',
] as const

export const HEAL_HISTORY_STATUS_KEYS = [
  'healed',
  'undone',
] as const

export const DELIVERY_OUTCOME_KEYS = [
  'success',
  'failure',
] as const

export const HEALTH_STATUS_KEYS = [
  'ok',
  'warning',
  'critical',
] as const

export const HEAL_OUTCOME_KEYS = [
  'healed',
  'already_renderable',
  'unrecoverable',
  'unverifiable',
  'skipped',
] as const

export const ACTOR_TYPE_KEYS = [
  'user',
  'system',
  'anonymous',
] as const

export const DRIFT_ELIGIBILITY_KEYS = [
  'no_known_block',
  'blocked',
  'runtime_check_required',
] as const
