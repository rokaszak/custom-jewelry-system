import { I18nProvider, TimeField } from '@heroui/react'
import { parseTime } from '@internationalized/date'
import { cx } from '../../components/ui'
import { pad } from './calendarUtils'

function timeValue(v) {
  if (!v || !/^\d{1,2}:\d{2}/.test(v)) return null
  try {
    return parseTime(v.slice(0, 5).padStart(5, '0'))
  } catch {
    return null
  }
}

export default function TimeInput({ value, onChange, label, invalid, size, className, isDisabled }) {
  return (
    <I18nProvider locale="lt-LT">
      <TimeField
        aria-label={label || 'Time'}
        className={cx('cjs-date shrink-0', size === 'sm' ? 'cjs-date-sm w-[76px]' : 'w-[92px]', className)}
        value={timeValue(value)}
        hourCycle={24}
        shouldForceLeadingZeros
        isDisabled={isDisabled}
        isInvalid={!!invalid}
        onChange={(t) => onChange(t ? `${pad(t.hour)}:${pad(t.minute)}` : '')}
      >
        <TimeField.Group fullWidth>
          <TimeField.Input>{(segment) => <TimeField.Segment segment={segment} />}</TimeField.Input>
        </TimeField.Group>
      </TimeField>
    </I18nProvider>
  )
}
