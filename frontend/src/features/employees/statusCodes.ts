/**
 * Catalog status codes the Employee 360 UI treats specially. These MIRROR backend code-level policy (they are
 * not exposed by any API), so the backend stays the authority — every value here only shapes what the form
 * offers or how a row is labelled; the server still validates each request.
 */

/** Seeded S06 codes whose behavior ends the relationship: shown as a terminal EVENT, never as a temporary status. */
export const TERMINAL_STATUS_CODES: ReadonlySet<string> = new Set(['retired', 'resigned', 'contract_ended', 'deceased', 'martyred'])

/** S34: the two legacy return-intention codes are no longer employment statuses (RetiredEmploymentStatusCodes). */
export const RETIRED_STATUS_CODES: ReadonlySet<string> = new Set(['wants_to_return', 'does_not_want_to_return'])

/** BoundedEmploymentStatusPolicy: statuses that accept an end date (optional) … */
export const OPTIONAL_END_STATUS_CODES: ReadonlySet<string> = new Set(['traveling', 'suspended'])

/** … and those that require one. Every other status is open-ended and the server rejects an end date for it. */
export const REQUIRED_END_STATUS_CODES: ReadonlySet<string> = new Set(['unpaid_leave', 'external_sick_leave'])

/** S41: the pay indicator is meaningful only for `traveling`. */
export const TRAVEL_PAY_STATUS_CODE = 'traveling'
