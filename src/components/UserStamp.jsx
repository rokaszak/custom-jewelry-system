import { Avatar } from '@heroui/react'
import { dateTime } from '../lib/format'
import { initials } from '../pages/settings/logFormat'
import { cx } from './ui'

export default function UserStamp({ user, avatar, at, className }) {
  return (
    <div className={cx('flex min-w-0 items-center gap-2.5', className)}>
      <Avatar size="sm" className="shrink-0">
        {avatar ? <Avatar.Image src={avatar} alt="" /> : null}
        <Avatar.Fallback>{initials(user)}</Avatar.Fallback>
      </Avatar>
      <div className="min-w-0">
        <div className="truncate text-[13px] font-medium text-foreground">{user}</div>
        <div className="text-[11.5px] tabular-nums text-muted">{dateTime(at)}</div>
      </div>
    </div>
  )
}
