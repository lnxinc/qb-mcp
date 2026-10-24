<?php

namespace App\Services\QuickBooks;

use App\Models\QuickBooksConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class CompanyResolver
{
    /**
     * Get every connected company, optionally limited to the teams of the given user.
     *
     * @return Collection<int, QuickBooksConnection>
     */
    public function all(?User $user = null): Collection
    {
        return $this->baseQuery($user)
            ->with('team')
            ->orderBy('company_name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Find a connected company by its slug, realm ID, or company name.
     */
    public function resolve(string $identifier, ?User $user = null): ?QuickBooksConnection
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return $this->baseQuery($user)->where('slug', Str::lower($identifier))->first()
            ?? $this->baseQuery($user)->where('realm_id', $identifier)->first()
            ?? $this->baseQuery($user)
                ->whereRaw('LOWER(company_name) = ?', [Str::lower($identifier)])
                ->orderBy('id')
                ->first();
    }

    /**
     * @return Builder<QuickBooksConnection>
     */
    protected function baseQuery(?User $user): Builder
    {
        return QuickBooksConnection::query()->when(
            $user,
            fn (Builder $query, User $user) => $query->whereIn('team_id', $user->teams()->select('teams.id')),
        );
    }
}
