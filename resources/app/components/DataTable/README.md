# DataTable Component

A server-driven, accessible data table for paginated, sortable, searchable data.
Built for Inertia.js — the server owns the state, the component renders it and emits changes via `router.get()`.

## Quick Start

```vue
<script setup lang="ts">
import DataTable from "Components/DataTable/DataTable.vue";
import type { ColumnDef, TableResponse } from "Types/dataTable";

interface UserRow {
    id: string;
    name: string;
    email: string;
    role: string;
}

defineProps<{
    table: TableResponse<UserRow>;
}>();

const columns: ColumnDef<UserRow>[] = [
    { key: "name", label: "Name", sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "email", label: "Email", sortable: true, visibleInCard: true },
    { key: "role", label: "Role" }
];
</script>

<template>
    <data-table :columns="columns" :response="table" base-url="/admin/users">
        <template #empty>
            <p>No users found.</p>
        </template>
    </data-table>
</template>
```

## Props

| Prop         | Type               | Default | Description                                                                 |
| ------------ | ------------------ | ------- | --------------------------------------------------------------------------- |
| `columns`    | `ColumnDef<T>[]`   | —       | Column definitions (see below).                                             |
| `response`   | `TableResponse<T>` | —       | Server response containing rows, pagination, sort, and search state.        |
| `selectable` | `boolean`          | `false` | Enable row selection checkboxes.                                            |
| `baseUrl`    | `string`           | `""`    | Base path for Inertia navigation. Falls back to `window.location.pathname`. |

## Slots

| Slot               | Scope                       | Description                                                              |
| ------------------ | --------------------------- | ------------------------------------------------------------------------ |
| `#header-{key}`    | `{ column: ColumnDef<T> }`  | Custom header content for a column. Fallback: `column.label` as text.    |
| `#cell-{key}`      | `{ row: T }`                | Custom renderer for a column. Fallback: raw `row[key]` as text.          |
| `#actions`         | `{ row: T }`                | Row action popover content. Render as `<li>` with `popover-list-item`.   |
| `#toolbar-actions` | `{ selectedIds: string[] }` | Toolbar buttons (e.g. bulk actions). Only visible when slot is provided. |
| `#filters`         | `{ filters, setFilters }`   | Filter UI rendered above the search input. See below.                    |
| `#empty`           | —                           | Content shown when `rows` is empty and not loading.                      |

### Header Slots

Columns without a matching `#header-{key}` slot render the `label` as text.
Columns with a slot get full control over the header content:

```vue
<template #header-created_at>
    <icon name="calendar" :size="1" />
</template>
```

When a header slot replaces the text label, the sort button automatically gets an `aria-label`
set to the column's `label` value so the header remains accessible to screen readers.

### Cell Slots

Columns without a matching `#cell-{key}` slot render the raw value as text.
Columns with a slot get full control:

```vue
<template #cell-price="{ row }">
    <strong>{{ formatCurrency(row.price) }}</strong>
</template>
```

Cell slots render in **both** the desktop table and the mobile card layout — write them once.

### Row Actions

The `#actions` slot renders inside a shared popover (one instance for the whole table, not per-row).
Use the existing popover CSS classes:

```vue
<template #actions="{ row }">
    <li><button class="popover-list-item" @click="edit(row)">Edit</button></li>
    <li><button class="popover-list-item popover-list-item--caution" @click="remove(row)">Delete</button></li>
</template>
```

### Toolbar Actions

Shown to the right of the search input. The slot exposes `selectedIds` so you can build bulk actions:

```vue
<template #toolbar-actions="{ selectedIds }">
    <button v-if="selectedIds.length > 0" @click="moveCards(selectedIds)">Move {{ selectedIds.length }} cards</button>
</template>
```

### Filters

Rendered above the search input. The table does not know which filters exist — the slot owns
them. `filters` is what the server applied (`response.filters`); `setFilters(patch)` navigates
with the patch merged into the current query (`null` removes a parameter) and resets to page 1:

```vue
<template #filters="{ filters, setFilters }">
    <card-stack-filters :filters="filters" :options="filterOptions" @change="setFilters" />
</template>
```

Filters are ordinary query parameters next to `sort`, `page` and `search`, so every other
navigation (sort, page, search) carries them along unchanged.

## Column Definition

```ts
interface ColumnDef<T extends { id: string }> {
    key: keyof T & string; // field name in row data — type-safe
    label: string; // display label (pass translated string)
    sortable?: boolean; // default false
    width?: string; // CSS value, default 'auto'
    align?: "left" | "center" | "right"; // default 'left'
    visibleInCard?: boolean; // show in mobile card layout, default false
    cardPrimary?: boolean; // main column at the top of the card, first wins
    cellClass?: string; // extra CSS class(es) for <td>
}
```

## Server Response Contract

The server must return this shape as an Inertia prop:

```ts
interface TableResponse<T> {
    rows: T[]; // current page of data
    total: number; // total row count across all pages
    page: number; // current page (1-based)
    pageSize: number | null; // null = no pagination
    sort: { key: string; direction: "asc" | "desc" } | null;
    search: string | null;
    filters: Record<string, string | string[]> | null; // applied filters, null when none
}
```

Every row object **must** have an `id: string` field (UUID).
Rows may optionally have an `href: string` field — if present, the row becomes clickable.

## URL State

Sort, pagination, search and filter state live in URL query parameters for bookmarkability:

```
?sort=name&dir=asc&page=2&pageSize=25&search=Lightning&lang=de
```

The component reads initial state from the `response` prop (server is the source of truth)
and emits changes via `router.get()` with `preserveState` and `preserveScroll`.
Existing query params are preserved when navigating (e.g. changing page keeps the current sort and pageSize).

## Server Implementation (Laravel)

Use `DataTableService::buildResponse()` to handle sort/search/pagination in the controller:

```php
use App\Services\DataTableService;

public function show(Request $request, Container $container): Response
{
    $query = $container->cardStacks()
        ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
        ->leftJoin('sets', 'default_cards.set_id', '=', 'sets.id')
        ->select([
            'card_stacks.*',
            'default_cards.name as card_name',
            'sets.name as set_name',
        ]);

    $table = DataTableService::buildResponse(
        query: $query,
        request: $request,
        sortable: ['name', 'set_name', 'amount'],     // whitelist of allowed sort keys
        sortColumnMap: [                                // map sort keys to DB columns
            'name'     => 'default_cards.name',
            'set_name' => 'sets.name',
        ],
        defaultSort: 'name',
        searchCallback: function ($q, $search) {       // null to disable search
            $q->where(function ($q) use ($search) {
                $q->where('default_cards.name', 'like', "%{$search}%")
                  ->orWhere('sets.name', 'like', "%{$search}%");
            });
        },
        rowMapper: function ($stack) {                  // transform model → plain array
            return [
                'id'       => $stack->id,
                'name'     => $stack->card_name,
                'set_name' => $stack->set_name,
                'amount'   => $stack->amount,
            ];
        },
    );

    return Inertia::render('MyPage', [
        'table' => $table,
    ]);
}
```

`DataTableService` handles:

- Sort key validation against the whitelist (falls back to `defaultSort`)
- Sort direction validation (`asc`/`desc`)
- Page size validation (25, 50, or 100)
- Search callback application
- Sort column mapping (sort key → actual DB column for joins)
- Pagination via Laravel's `paginate()`
- Response shaping to match the `TableResponse<T>` contract

Filters are applied by the caller, not by the service: narrow `$query` before calling
`buildResponse()` and pass the applied values as `filters:` so they are echoed back. Read them
as forgivingly as the service reads sort and page size — drop an invalid value instead of
answering with a 422. `CardStackFilterService` is the worked example (collection and container
tables).

The `sortColumnMap` is needed when sort keys don't match DB column names (e.g. the frontend
sorts by `name` but the DB column is `default_cards.name` because of a join). Keys not in the
map fall through as-is (e.g. `amount` → `amount`).

## Sub-Components

All live in `Components/DataTable/`. The parent only imports `DataTable.vue` — the rest are internal.

| Component               | Responsibility                                                                                                                                                                |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **DataTable.vue**       | Orchestrator. Selection state, slot forwarding, Inertia navigation, loading overlay, aria-live announcements.                                                                 |
| **DataTableToolbar**    | Search input (750ms debounce, Enter searches at once, 2-character minimum; never overwritten by the echo of its own search), selection count badge, `#actions` slot.          |
| **DataTableHead**       | Sticky `<thead>` with sort buttons, `aria-sort` attributes, header checkbox (three-state).                                                                                    |
| **DataTableBody**       | `<tbody>` with cell slot rendering, row selection, clickable rows, three-dot action button.                                                                                   |
| **DataTableCards**      | Mobile card layout via `@container` query (<640px). Renders `visibleInCard` columns.                                                                                          |
| **DataTablePagination** | Page navigation (first/prev/next/last), page numbers with ellipsis truncation, jump-to-page, page size selector. Shows info + page size selector even on single-page results. |
| **DataTableActions**    | Shared row-action popover (one instance, repositioned per click). Uses CSS anchor positioning and the existing `popover-content` styles.                                      |

## Responsive Behavior

The component uses CSS **container queries** (not media queries) so it adapts based on its own
width, not the viewport. Both the `<table>` and the card layout are in the DOM at all times —
CSS `display: none` toggles visibility at the 640px container-width breakpoint.
With a max of 100 rows per page, the DOM duplication is negligible.

In the card layout:

- Only columns with `visibleInCard: true` are shown
- The column with `cardPrimary: true` renders at the top of each card
- Cell slots work identically in both layouts

## Selection

When `selectable` is enabled:

- Each row gets a checkbox
- The header checkbox has three states: unchecked (none), checked (all on page), indeterminate (some)
- Selection **persists across page changes** (IDs stored in component state)
- Selection **clears on sort, search, or filter changes**
- Selected IDs are exposed via the `#toolbar-actions` slot and via provide/inject (`DATA_TABLE_KEY`)

## Accessibility

- `<table>` with semantic `<thead>`/`<tbody>`/`<th>`/`<td>`
- Sort headers use `<button>` elements with `aria-sort` attributes
- `aria-busy="true"` on the table during Inertia navigation
- `aria-live="polite"` region announces sort and page changes to screen readers
- Popover focus management: focus returns to the three-dot button when the popover closes
- All interactive elements have `aria-label` attributes via i18n keys (`components.datatable.*`)

## Sticky Header

Column headers stick via `position: sticky`. Set the CSS custom property
`--datatable-sticky-offset` on a parent element to account for a fixed site header:

```css
.my-layout {
    --datatable-sticky-offset: 64px;
}
```

Default offset is `0`.

## i18n

All UI text uses keys under `components.datatable.*` in the i18n JSON files.
Column labels are passed as already-translated strings via the `label` field in `ColumnDef`.
