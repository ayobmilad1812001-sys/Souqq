import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'

import { ApiError } from '@/api/client'
import { categoriesApi } from '@/api/endpoints/categories'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useCategories } from '@/hooks/useCatalog'
import { queryKeys } from '@/lib/queryKeys'

export function CategoriesPage() {
  const queryClient = useQueryClient()
  const { data, isPending, error } = useCategories()
  const [name, setName] = useState('')

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.categories })
    // A category rename or removal changes what product listings show.
    void queryClient.invalidateQueries({ queryKey: ['products'] })
  }

  const createCategory = useMutation({
    mutationFn: (input: { name: string }) => categoriesApi.create(input),
    onSuccess: () => {
      setName('')
      invalidate()
    },
  })

  const deleteCategory = useMutation({
    mutationFn: (id: number) => categoriesApi.remove(id),
    onSuccess: invalidate,
  })

  if (isPending) return <PageSpinner />
  if (error) return <Alert tone="error">{errorMessage(error)}</Alert>

  // The API refuses with 409 when a category still holds products, rather than
  // cascading the delete into other sellers' inventory.
  const deleteConflict =
    deleteCategory.error instanceof ApiError && deleteCategory.error.isConflict

  return (
    <div className="mx-auto max-w-3xl">
      <h1 className="mb-6 text-2xl font-bold">Categories</h1>

      <Card className="mb-6">
        <CardBody>
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              if (name.trim()) createCategory.mutate({ name: name.trim() })
            }}
          >
            <div className="min-w-56 flex-1">
              <Input
                label="New category"
                placeholder="e.g. Home & Garden"
                hint="The slug is generated automatically and stays fixed after a rename."
                value={name}
                onChange={(event) => setName(event.target.value)}
              />
            </div>
            <Button type="submit" loading={createCategory.isPending}>
              Add
            </Button>
          </form>

          {createCategory.error && (
            <Alert tone="error" className="mt-3">
              {errorMessage(createCategory.error)}
            </Alert>
          )}
        </CardBody>
      </Card>

      {deleteCategory.error && (
        <Alert tone={deleteConflict ? 'warning' : 'error'} className="mb-4">
          {deleteConflict
            ? deleteCategory.error.message
            : errorMessage(deleteCategory.error)}
        </Alert>
      )}

      <div className="space-y-3">
        {data.data.map((category) => (
          <Card key={category.id}>
            <CardBody className="flex flex-wrap items-center gap-4">
              <div className="min-w-40 flex-1">
                <p className="font-medium">{category.name}</p>
                <p className="font-mono text-xs text-muted">{category.slug}</p>
              </div>

              {category.products_count !== undefined && (
                <Badge tone="info">{category.products_count} active</Badge>
              )}

              <Button
                variant="ghost"
                size="sm"
                loading={deleteCategory.isPending}
                onClick={() => deleteCategory.mutate(category.id)}
              >
                Delete
              </Button>
            </CardBody>
          </Card>
        ))}
      </div>
    </div>
  )
}
