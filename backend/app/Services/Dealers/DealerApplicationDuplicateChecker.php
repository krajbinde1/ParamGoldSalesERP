<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Models\DealerApplication;
use Illuminate\Database\Eloquent\Builder;

final class DealerApplicationDuplicateChecker
{
    /**
     * @return list<array{source: string, match_on: string, id: int, name: string}>
     */
    public function matches(DealerApplication $application): array
    {
        $matches = [];

        $mobile = trim((string) $application->mobile);
        $gst = strtoupper(trim((string) $application->gst_no));
        $firm = mb_strtolower(trim((string) $application->firm_name));
        $village = mb_strtolower(trim((string) $application->village));

        if ($mobile !== '') {
            $matches = array_merge(
                $matches,
                $this->dealerMatches('mobile', fn (Builder $q) => $q->where('mobile', $mobile)),
                $this->applicationMatches(
                    $application,
                    'mobile',
                    fn (Builder $q) => $q->where('mobile', $mobile),
                ),
            );
        }

        if ($gst !== '') {
            $matches = array_merge(
                $matches,
                $this->dealerMatches('gst_no', fn (Builder $q) => $q->where('gst_no', $gst)),
                $this->applicationMatches(
                    $application,
                    'gst_no',
                    fn (Builder $q) => $q->where('gst_no', $gst),
                ),
            );
        }

        if ($firm !== '' && $village !== '') {
            $matches = array_merge(
                $matches,
                $this->dealerMatches(
                    'firm_location',
                    fn (Builder $q) => $q
                        ->whereRaw('LOWER(firm_name) = ?', [$firm])
                        ->whereRaw('LOWER(village) = ?', [$village]),
                ),
                $this->applicationMatches(
                    $application,
                    'firm_location',
                    fn (Builder $q) => $q
                        ->whereRaw('LOWER(firm_name) = ?', [$firm])
                        ->whereRaw('LOWER(village) = ?', [$village]),
                ),
            );
        }

        $unique = [];
        foreach ($matches as $match) {
            $key = $match['source'].'-'.$match['id'].'-'.$match['match_on'];
            $unique[$key] = $match;
        }

        return array_values($unique);
    }

    /**
     * @param  callable(Builder<Dealer>): Builder<Dealer>  $constraint
     * @return list<array{source: string, match_on: string, id: int, name: string}>
     */
    private function dealerMatches(string $matchOn, callable $constraint): array
    {
        return $constraint(Dealer::query())
            ->limit(5)
            ->get(['id', 'firm_name'])
            ->map(fn (Dealer $dealer): array => [
                'source' => 'dealer',
                'match_on' => $matchOn,
                'id' => $dealer->id,
                'name' => $dealer->firm_name,
            ])
            ->all();
    }

    /**
     * @param  callable(Builder<DealerApplication>): Builder<DealerApplication>  $constraint
     * @return list<array{source: string, match_on: string, id: int, name: string}>
     */
    private function applicationMatches(DealerApplication $application, string $matchOn, callable $constraint): array
    {
        return $constraint(
            DealerApplication::query()
                ->whereKeyNot($application->id)
                ->whereNotIn('status', [DealerApplication::STATUS_REJECTED])
        )
            ->limit(5)
            ->get(['id', 'firm_name'])
            ->map(fn (DealerApplication $row): array => [
                'source' => 'application',
                'match_on' => $matchOn,
                'id' => $row->id,
                'name' => $row->firm_name,
            ])
            ->all();
    }
}
