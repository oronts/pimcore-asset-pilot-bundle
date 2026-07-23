import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { renderWithI18n } from '../../../../../../test/render'
import { BarChart } from './bar-chart'
import { DonutChart } from './donut-chart'

describe('chart summaries', () => {
  it('localizes the donut name and describes every segment', () => {
    renderWithI18n(
      <DonutChart segments={[{ label: 'Erledigt', value: 3, color: 'green' }, { label: 'Fehler', value: 1, color: 'red' }]} />,
      { language: 'de' },
    )

    const chart = screen.getByRole('img', { name: 'Ringdiagramm' })
    expect(chart).toHaveAccessibleDescription('Erledigt: 3 (75 %); Fehler: 1 (25 %)')
  })

  it('describes every bar value', () => {
    renderWithI18n(<BarChart items={[{ label: 'Images', value: 8 }, { label: 'PDFs', value: 2 }]} />)

    const chart = screen.getByRole('img', { name: 'Bar chart' })
    expect(chart).toHaveAccessibleDescription('Images: 8; PDFs: 2')
  })
})
