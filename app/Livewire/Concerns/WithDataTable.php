<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Standard list behaviour: search, status filter, whitelisted sorting and
 * pagination, all kept in the query string so lists survive refresh and can
 * be shared.
 */
trait WithDataTable
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $sortBy = '';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    public int $perPage = 15;

    /**
     * Columns the user may sort by, mapped to database columns.
     *
     * @return array<string, string>
     */
    abstract protected function sortableColumns(): array;

    /**
     * Default ordering when no (valid) sort is selected.
     */
    abstract protected function defaultSort(Builder $query): void;

    public function sort(string $column): void
    {
        if (! array_key_exists($column, $this->sortableColumns())) {
            return;
        }

        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    protected function applySorting(Builder $query): Builder
    {
        $columns = $this->sortableColumns();

        if (array_key_exists($this->sortBy, $columns)) {
            $query->orderBy($columns[$this->sortBy], $this->sortDirection === 'desc' ? 'desc' : 'asc');
        } else {
            $this->defaultSort($query);
        }

        return $query;
    }

    /**
     * Escaped LIKE pattern for the current search term.
     */
    protected function searchPattern(): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';
    }

    protected function hasSearch(): bool
    {
        return trim($this->search) !== '';
    }
}
