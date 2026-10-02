import type { Locale } from '../../i18n/locales'
import type { Messages } from '../../i18n/messages/types'
import type { MultiValueBucket } from './api'

type D = Messages['dashboard']

/** A catalog name in the active locale, falling back to the other language and then to the stable code. */
export function pickName(locale: Locale, ar: string | null | undefined, en: string | null | undefined, fallback: string): string {
  const primary = locale === 'ar' ? ar : en
  const secondary = locale === 'ar' ? en : ar
  return primary || secondary || fallback
}

const STATE_KEY = { NOT_RECORDED: 'stateNotRecorded', NOT_APPLICABLE: 'stateNotApplicable', UNMAPPED: 'stateUnmapped' } as const

export function stateLabel(d: D, state: string): string | null {
  return state in STATE_KEY ? d[STATE_KEY[state as keyof typeof STATE_KEY]] : null
}

export function dutyLabel(d: D, state: string): string {
  switch (state) {
    case 'HAS_ON_DUTY':
      return d.dutyHasOnDuty
    case 'NO_ON_DUTY':
      return d.dutyNoOnDuty
    case 'INDETERMINATE':
      return d.dutyIndeterminate
    default:
      return state
  }
}

export function bandLabel(d: D, band: string): string {
  if (band === 'NOT_RECORDED') {
    return d.stateNotRecorded
  }
  if (band === 'INCOMPLETE') {
    return d.serviceIncomplete
  }
  return band
}

export function ageStateLabel(d: D, state: string): string {
  switch (state) {
    case 'CALCULABLE':
      return d.ageStateCalculable
    case 'NOT_RECORDED':
      return d.ageStateNotRecorded
    case 'NOT_CALCULABLE':
      return d.ageStateNotCalculable
    default:
      return state
  }
}

export function statusLabel(d: D, code: string): string {
  switch (code) {
    case 'ON_DUTY':
      return d.statusOnDuty
    case 'TRAVELING':
      return d.statusTraveling
    case 'CAPTIVE':
      return d.statusCaptive
    case 'SUSPENDED':
      return d.statusSuspended
    case 'UNPAID_LEAVE':
      return d.statusUnpaidLeave
    case 'EXTERNAL_SICK_LEAVE':
      return d.statusExternalSickLeave
    case 'INDETERMINATE':
      return d.statusIndeterminate
    default:
      return code
  }
}

export function reasonLabel(d: D, reason: string): string {
  switch (reason) {
    case 'RETIRED':
      return d.reasonRetired
    case 'RESIGNED':
      return d.reasonResigned
    case 'CONTRACT_ENDED':
      return d.reasonContractEnded
    case 'MARTYRED':
      return d.reasonMartyred
    case 'DECEASED':
      return d.reasonDeceased
    case 'NOT_RECORDED':
      return d.reasonNotRecorded
    default:
      return reason
  }
}

export function weekdayLabel(d: D, weekday: string): string {
  const map: Record<string, string> = {
    MONDAY: d.weekdayMonday,
    TUESDAY: d.weekdayTuesday,
    WEDNESDAY: d.weekdayWednesday,
    THURSDAY: d.weekdayThursday,
    FRIDAY: d.weekdayFriday,
    SATURDAY: d.weekdaySaturday,
    SUNDAY: d.weekdaySunday,
  }
  return map[weekday] ?? weekday
}

export function movementLabel(d: D, movement: string): string {
  const map: Record<string, string> = {
    PLACEMENT: d.movementPlacement,
    FULL_SECONDMENT: d.movementFullSecondment,
    WORKPLACE_ASSIGNMENT: d.movementWorkplaceAssignment,
    PARTIAL_SECONDMENT: d.movementPartialSecondment,
    PLACEMENT_UNDERLYING_OF_PARTIAL_ALLOCATION: d.movementUnderlying,
  }
  return map[movement] ?? movement
}

export function nonDeterminableLabel(d: D, state: string): string {
  switch (state) {
    case 'UNRESOLVED':
      return d.nonDeterminableUnresolved
    case 'AMBIGUOUS_MOVEMENT_STATE':
      return d.nonDeterminableAmbiguous
    default:
      return state
  }
}

export function dataQualityLabel(d: D, code: string): string {
  const map: Record<string, string> = {
    INDETERMINATE_STATUS_COVERAGE: d.dqIndeterminateStatusCoverage,
    UNKNOWN_LEGACY_RELATIONSHIP_END: d.dqUnknownLegacyRelationshipEnd,
    RELATIONSHIP_END_REASON_NOT_RECORDED: d.dqRelationshipEndReasonNotRecorded,
    PRIMARY_QUALIFICATION_REQUIRED: d.dqPrimaryQualificationRequired,
    BIRTH_DATE_AFTER_REPORT_DATE: d.dqBirthDateAfterReportDate,
    TRAVEL_PAY_STATUS_NOT_RECORDED: d.dqTravelPayStatusNotRecorded,
    GENDER_NOT_RECORDED: d.dqGenderNotRecorded,
    ORGANIZATIONAL_PLACEMENT_NOT_RECORDED: d.dqOrganizationalPlacementNotRecorded,
    ACTUAL_WORKPLACE_NOT_DETERMINABLE: d.dqActualWorkplaceNotDeterminable,
  }
  return map[code] ?? code
}

/** The label of a multi-value bucket: a missing/unmapped state keeps its own label (never "other"); a recorded value shows its catalog name. */
export function multiValueLabel(d: D, locale: Locale, bucket: MultiValueBucket): string {
  const state = bucket.state ?? ''
  const own = stateLabel(d, state)
  if (own !== null) {
    return own
  }
  if (bucket.status !== undefined) {
    return statusLabel(d, bucket.status)
  }
  return pickName(locale, bucket.name_ar, bucket.name_en, bucket.code ?? bucket.relationship_type ?? bucket.bucket)
}
