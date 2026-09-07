import { useQuery } from '@tanstack/react-query'

import { statsApi } from '@/api/endpoints/stats'
import { queryKeys } from '@/lib/queryKeys'

export function useStats() {
  return useQuery({
    queryKey: queryKeys.stats,
    queryFn: () => statsApi.get(),
  })
}
