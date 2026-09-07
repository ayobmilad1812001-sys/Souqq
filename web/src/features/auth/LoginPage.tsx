import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { z } from 'zod'

import { ApiError } from '@/api/client'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { errorMessage, useLogin } from '@/hooks/useAuth'

const schema = z.object({
  email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
})

type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const login = useLogin()
  const navigate = useNavigate()
  const location = useLocation()

  const redirectTo = (location.state as { from?: string } | null)?.from ?? '/'

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  const onSubmit = handleSubmit((values) => {
    login.mutate(values, {
      onSuccess: () => navigate(redirectTo, { replace: true }),
      onError: (error) => {
        // The API returns a deliberately identical message for an unknown
        // account and a wrong password, so it cannot be used to enumerate users.
        if (error instanceof ApiError && error.isValidation) {
          for (const [field, message] of Object.entries(error.fieldErrors())) {
            setError(field as keyof FormValues, { message })
          }
        }
      },
    })
  })

  const rateLimited = login.error instanceof ApiError && login.error.isRateLimited

  return (
    <div className="mx-auto max-w-md">
      <h1 className="mb-6 text-2xl font-bold">Sign in</h1>

      <Card>
        <CardBody className="space-y-4">
          {login.error && (
            <Alert tone="error">
              {rateLimited
                ? 'Too many sign-in attempts. Please wait a minute and try again.'
                : errorMessage(login.error)}
            </Alert>
          )}

          <form onSubmit={onSubmit} className="space-y-4" noValidate>
            <Input
              label="Email"
              type="email"
              autoComplete="email"
              error={errors.email?.message}
              {...register('email')}
            />

            <Input
              label="Password"
              type="password"
              autoComplete="current-password"
              error={errors.password?.message}
              {...register('password')}
            />

            <Button type="submit" className="w-full" loading={login.isPending}>
              Sign in
            </Button>
          </form>

          <p className="text-sm text-muted">
            No account?{' '}
            <Link to="/register" className="font-medium text-violet-700 hover:underline">
              Create one
            </Link>
          </p>
        </CardBody>
      </Card>

      <Alert tone="info" className="mt-4">
        <p className="font-medium">Demo accounts</p>
        <p className="mt-1 text-xs">
          customer@libyamarket.test &middot; admin@libyamarket.test &mdash; password:{' '}
          <code className="font-mono">password</code>
        </p>
      </Alert>
    </div>
  )
}
