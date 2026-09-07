import { useState } from 'react'

import { ApiError } from '@/api/client'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Select, Textarea } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useCreateReview, useDeleteReview, useProductReviews } from '@/hooks/useCatalog'
import { useUser } from '@/stores/auth'

function Stars({ rating }: { rating: number }) {
  return (
    <span aria-label={`${rating} out of 5`} className="text-amber-500">
      {'\u2605'.repeat(rating)}
      <span className="text-line">{'\u2605'.repeat(5 - rating)}</span>
    </span>
  )
}

export function ReviewSection({ productId }: { productId: number }) {
  const user = useUser()
  const { data, isPending } = useProductReviews(productId)
  const createReview = useCreateReview(productId)
  const deleteReview = useDeleteReview(productId)

  const [rating, setRating] = useState('5')
  const [comment, setComment] = useState('')

  const reviews = data?.data ?? []

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    createReview.mutate(
      { rating: Number(rating), comment: comment.trim() || undefined },
      { onSuccess: () => setComment('') },
    )
  }

  // Only verified purchasers may review, and a seller may not review their own
  // product. Rather than duplicating that rule here, we let the API answer and
  // explain a 403 when it comes back.
  const forbidden = createReview.error instanceof ApiError && createReview.error.isForbidden

  return (
    <section className="mt-8">
      <h2 className="mb-4 text-lg font-semibold">Reviews</h2>

      {isPending ? (
        <Spinner />
      ) : reviews.length === 0 ? (
        <p className="text-sm text-muted">No reviews yet.</p>
      ) : (
        <ul className="space-y-3">
          {reviews.map((review) => (
            <li key={review.id}>
              <Card>
                <CardBody className="py-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2">
                        <Stars rating={review.rating} />
                        <span className="text-sm font-medium">{review.author?.name}</span>
                      </div>
                      {review.comment && (
                        <p className="mt-1 text-sm text-ink-soft">{review.comment}</p>
                      )}
                    </div>

                    {(user?.id === review.author?.id || user?.role === 'admin') && (
                      <Button
                        variant="ghost"
                        size="sm"
                        loading={deleteReview.isPending}
                        onClick={() => deleteReview.mutate(review.id)}
                      >
                        Delete
                      </Button>
                    )}
                  </div>
                </CardBody>
              </Card>
            </li>
          ))}
        </ul>
      )}

      {user && (
        <Card className="mt-6">
          <CardBody>
            <h3 className="mb-3 font-medium">Write a review</h3>

            {createReview.error && (
              <Alert tone="error" className="mb-3">
                {forbidden
                  ? 'Only customers who have bought this product can review it.'
                  : errorMessage(createReview.error)}
              </Alert>
            )}

            {createReview.isSuccess && !createReview.error && (
              <Alert tone="success" className="mb-3">
                Thanks &mdash; your review has been saved.
              </Alert>
            )}

            <form onSubmit={handleSubmit} className="space-y-3">
              <Select
                label="Rating"
                value={rating}
                onChange={(event) => setRating(event.target.value)}
              >
                {[5, 4, 3, 2, 1].map((value) => (
                  <option key={value} value={value}>
                    {value} star{value === 1 ? '' : 's'}
                  </option>
                ))}
              </Select>

              <Textarea
                label="Comment"
                hint="Optional."
                maxLength={2000}
                value={comment}
                onChange={(event) => setComment(event.target.value)}
              />

              <Button type="submit" loading={createReview.isPending}>
                Submit review
              </Button>
            </form>
          </CardBody>
        </Card>
      )}
    </section>
  )
}
