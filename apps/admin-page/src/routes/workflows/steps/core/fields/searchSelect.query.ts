import { keepPreviousData, useInfiniteQuery, useQuery, type InfiniteData, type QueryKey } from '@tanstack/react-query';

import type { OptionMeta } from '@/types/localize';
import { api } from '@/lib/api/api';

/** What a data field's `search` says it searches. Mirrors PHP `SearchRoutes`. */
export type SearchType = 'product' | 'category' | 'coupon';

const routes: Record<SearchType, string> = { product: 'products', category: 'categories', coupon: 'coupons' };

/** `hint`: small text beside a row, an id or a status. */
type SearchItem = OptionMeta & { hint?: string };

interface SearchPage {
  items: SearchItem[];
  hasMore: boolean;
}

/** PHP `SearchRoutes` takes at most this many `include[]` a request. */
const INCLUDE_MAX = 100;

const chunks = (values: string[]) =>
  Array.from({ length: Math.ceil(values.length / INCLUDE_MAX) }, (_, i) =>
    values.slice(i * INCLUDE_MAX, (i + 1) * INCLUDE_MAX),
  );

// Its own fetch: `steps/` doesn't import `api/` (dev convention).
const fetchPage = (type: SearchType, params: URLSearchParams) =>
  api.get(`search/${routes[type]}`, { searchParams: params }).json<SearchPage>();

/** One server page at a time for a typed term; only while `enabled` (the list is open). */
export const useSearchOptions = (type: SearchType, term: string, enabled: boolean) =>
  useInfiniteQuery<SearchPage, Error, InfiniteData<SearchPage>, QueryKey, number>({
    queryKey: ['search', type, term],
    enabled,
    initialPageParam: 1,
    // Rows of the same type while a new term loads; another type's rows could be picked by mistake.
    placeholderData: (previous, previousQuery) => (previousQuery?.queryKey[1] === type ? previous : undefined),
    queryFn: ({ pageParam }) => fetchPage(type, new URLSearchParams({ term, page: String(pageParam) })),
    getNextPageParam: (last, _pages, page) => (last.hasMore ? page + 1 : undefined),
  });

/** The labels of a saved rule's values, which the search pages may not have reached. */
export const useSearchLabels = (type: SearchType, values: string[]) =>
  useQuery({
    queryKey: ['search', type, 'include', values],
    enabled: values.length > 0,
    // Chips keep their names while a pick changes the list.
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const pages = await Promise.all(
        chunks(values).map((chunk) => {
          const params = new URLSearchParams();
          for (const value of chunk) params.append('include[]', value);
          return fetchPage(type, params);
        }),
      );
      return pages.flatMap((page) => page.items);
    },
  });

/** Custom field names other workflows use: suggestions, any other key can be typed. */
export const useCustomerMetaKeys = (enabled: boolean) =>
  useQuery({
    queryKey: ['search', 'customer-meta-keys'],
    enabled,
    // Each read decodes every workflow, and hints needn't be fresh to the second.
    staleTime: 60_000,
    queryFn: () => api.get('search/customer-meta-keys').json<{ keys: string[] }>(),
  });
