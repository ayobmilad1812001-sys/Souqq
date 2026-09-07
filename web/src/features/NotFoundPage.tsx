import { Link } from 'react-router-dom'

import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'

export function NotFoundPage() {
  return (
    <EmptyState
      title="Page not found"
      description="The page you were looking for does not exist."
      action={
        <Link to="/">
          <Button>Back to the catalogue</Button>
        </Link>
      }
    />
  )
}

export function ForbiddenPage() {
  return (
    <EmptyState
      title="Not allowed"
      description="Your account does not have permission to view this page."
      action={
        <Link to="/">
          <Button>Back to the catalogue</Button>
        </Link>
      }
    />
  )
}
