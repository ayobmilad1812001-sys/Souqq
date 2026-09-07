import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { z } from 'zod'

import { ApiError } from '@/api/client'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Select, Textarea } from '@/components/ui/Input'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import {
  useCategories,
  useCreateProduct,
  useProduct,
  useUpdateProduct,
} from '@/hooks/useCatalog'

// Mirrors the server's Form Request. `decimal:0,2` there matches DECIMAL(10,2)
// in the column, so 10.999 is a 422 rather than a silent round to 11.00.
const schema = z.object({
  name: z.string().min(1, 'Name is required.').max(255),
  description: z.string().min(1, 'Description is required.'),
  price: z
    .string()
    .regex(/^\d+(\.\d{1,2})?$/, 'Use a number with at most 2 decimal places.')
    .refine((value) => Number(value) >= 0.01, 'Price must be at least 0.01.'),
  sku: z.string().min(1, 'SKU is required.').max(64),
  stock_quantity: z.coerce.number().int().min(0, 'Stock cannot be negative.'),
  category_id: z.coerce.number().int().positive('Choose a category.'),
  is_active: z.boolean(),
})

type FormValues = z.input<typeof schema>

export function ProductFormPage() {
  const { id } = useParams<{ id: string }>()
  const isEditing = id !== undefined && id !== 'new'
  const productId = Number(id)

  const navigate = useNavigate()
  const { data: categories } = useCategories()
  const existing = useProduct(isEditing ? productId : 0)

  const createProduct = useCreateProduct()
  const updateProduct = useUpdateProduct(productId)
  const mutation = isEditing ? updateProduct : createProduct

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { is_active: true, stock_quantity: 0 },
  })

  useEffect(() => {
    if (isEditing && existing.data) {
      const product = existing.data.data
      reset({
        name: product.name,
        description: product.description,
        price: product.price,
        sku: product.sku,
        stock_quantity: product.stock_quantity,
        category_id: product.category?.id,
        is_active: product.is_active,
      })
    }
  }, [isEditing, existing.data, reset])

  const onSubmit = handleSubmit((values) => {
    // There is no seller_id: ownership comes from the token. Sending one would
    // be ignored by the API anyway.
    const payload = schema.parse(values)

    mutation.mutate(payload, {
      onSuccess: () => navigate('/seller/products'),
      onError: (error) => {
        // The server is the authority. Anything it rejects that our schema
        // allowed is a gap in the schema, so surface it on the field.
        if (error instanceof ApiError && error.isValidation) {
          for (const [field, message] of Object.entries(error.fieldErrors())) {
            setError(field as keyof FormValues, { message })
          }
        }
      },
    })
  })

  if (isEditing && existing.isPending) return <PageSpinner />

  return (
    <div className="mx-auto max-w-2xl">
      <Link to="/seller/products" className="text-sm text-violet-700 hover:underline">
        &larr; Back to inventory
      </Link>

      <h1 className="mt-4 mb-6 text-2xl font-bold">
        {isEditing ? 'Edit product' : 'Add a product'}
      </h1>

      <Card>
        <CardBody className="space-y-4">
          {mutation.error && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}

          <form onSubmit={onSubmit} className="space-y-4" noValidate>
            <Input label="Name" error={errors.name?.message} {...register('name')} />

            <Textarea
              label="Description"
              error={errors.description?.message}
              {...register('description')}
            />

            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                label="Price"
                inputMode="decimal"
                placeholder="0.00"
                hint="Up to 2 decimal places."
                error={errors.price?.message}
                {...register('price')}
              />

              <Input
                label="SKU"
                hint="Unique across the platform."
                error={errors.sku?.message}
                {...register('sku')}
              />

              <Input
                label="Stock quantity"
                type="number"
                min={0}
                error={errors.stock_quantity?.message}
                {...register('stock_quantity')}
              />

              <Select
                label="Category"
                error={errors.category_id?.message}
                {...register('category_id')}
              >
                <option value="">Choose a category</option>
                {categories?.data.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))}
              </Select>
            </div>

            <label className="flex items-center gap-2 text-sm text-ink-soft">
              <input
                type="checkbox"
                className="size-4 rounded border-line"
                {...register('is_active')}
              />
              Visible in the catalogue
            </label>

            <div className="flex gap-2">
              <Button type="submit" loading={mutation.isPending}>
                {isEditing ? 'Save changes' : 'Create product'}
              </Button>
              <Link to="/seller/products">
                <Button type="button" variant="secondary">
                  Cancel
                </Button>
              </Link>
            </div>
          </form>
        </CardBody>
      </Card>
    </div>
  )
}
