import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { StatusBadge } from './StatusBadge'
import { STATUS_KEYS, statusKeyFromCode } from './status'

describe('StatusBadge', () => {
  it('always renders a text label next to an icon, never color alone', () => {
    render(<StatusBadge status="traveling">مسافر</StatusBadge>)

    const badge = screen.getByText('مسافر')
    expect(badge).toHaveAttribute('data-status', 'traveling')
    expect(badge.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
  })

  it('maps every seeded employment-status code to its own visual key and unknown codes to neutral', () => {
    const codes = ['on_duty', 'traveling', 'captive', 'suspended', 'unpaid_leave', 'external_sick_leave', 'retired', 'resigned', 'contract_ended', 'deceased', 'martyred']
    const keys = codes.map((code) => statusKeyFromCode(code))

    expect(new Set(keys).size).toBe(codes.length)
    expect(statusKeyFromCode('something_new')).toBe('inactive')
    expect(statusKeyFromCode(null)).toBe('inactive')
    for (const key of keys) {
      expect(STATUS_KEYS).toContain(key)
    }
  })
})
