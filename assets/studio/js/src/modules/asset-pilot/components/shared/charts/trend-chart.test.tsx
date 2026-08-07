import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { renderWithI18n } from '../../../../../../test/render'
import { TrendChart } from './trend-chart'

const points = [
  { label: 'June', value: 10 },
  { label: 'July', value: 20 },
]

describe('TrendChart', () => {
  it('uses a localized accessible name with the formatted latest value', () => {
    renderWithI18n(
      <TrendChart points={points} formatValue={value => `${value} MB`} />,
      { language: 'de' },
    )

    const chart = screen.getByRole('img', { name: 'Trend, letzter Wert 20 MB' })
    expect(chart).toBeInTheDocument()
    expect(chart).toHaveAccessibleDescription('June: 10 MB; July: 20 MB')
  })
})
