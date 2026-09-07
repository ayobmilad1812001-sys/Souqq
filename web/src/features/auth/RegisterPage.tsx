import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { z } from 'zod'

import { ApiError } from '@/api/client'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Select } from '@/components/ui/Input'
import { errorMessage, useRegister } from '@/hooks/useAuth'

// Mirrors the server rules: min 8, mixed case, at least one number.
// Client validation is a convenience; the server stays the authority.
const schema = z
  .object({
    name: z.string().min(1, 'Name is required.').max(255),
    email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
    password: z
      .string()
      .min(8, 'At least 8 characters.')
      .regex(/[a-z]/, 'Include a lowercase letter.')
      .regex(/[A-Z]/, 'Include an uppercase letter.')
      .regex(/[0-9]/, 'Include a number.'),
    password_confirmation: z.string(),
    // 'admin' is deliberately absent: the API rejects it, so offering it would
    // only produce a confusing 422.
    role: z.enum(['customer', 'seller']),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })

type FormValues = z.infer<typeof schema>

export function RegisterPage() {
  const registerUser = useRegister()
  const navigate = useNavigate()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { role: 'customer' },
  })

  const onSubmit = handleSubmit((values) => {
    registerUser.mutate(values, {
      onSuccess: () => navigate('/', { replace: true }),
      onError: (error) => {
        if (error instanceof ApiError && error.isValidation) {
          for (const [field, message] of Object.entries(error.fieldErrors())) {
            setError(field as keyof FormValues, { message })
          }
        }
      },
    })
  })

  return (
    <div className="mx-auto max-w-md">
      <h1 className="mb-6 text-2xl font-bold">Create an account</h1>

      <Card>
        <CardBody className="space-y-4">
          {registerUser.error && <Alert tone="error">{errorMessage(registerUser.error)}</Alert>}

          <form onSubmit={onSubmit} className="space-y-4" noValidate>
            <Input
              label="Full name"
              autoComplete="name"
              error={errors.name?.message}
              {...register('name')}
            />

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
              autoComplete="new-password"
              hint="At least 8 characters, with upper and lower case and a number."
              error={errors.password?.message}
              {...register('password')}
            />

            <Input
              label="Confirm password"
              type="password"
              autoComplete="new-password"
              error={errors.password_confirmation?.message}
              {...register('password_confirmation')}
            />

            <Select label="I want to" error={errors.role?.message} {...register('role')}>
              <option value="customer">Buy on LibyaMarket</option>
              <option value="seller">Sell on LibyaMarket</option>
            </Select>

            <Button type="submit" className="w-full" loading={registerUser.isPending}>
              Create account
            </Button>
          </form>

          <p className="text-sm text-muted">
            Already registered?{' '}
            <Link to="/login" className="font-medium text-violet-700 hover:underline">
              Sign in
            </Link>
          </p>
        </CardBody>
      </Card>
    </div>
  )
}
