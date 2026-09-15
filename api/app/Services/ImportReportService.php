<?php

namespace App\Services;

use App\Models\Import;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ImportReportService
{
    private const SCALE = 6;

    /**
     * Aggregates the records of one import in a single streaming pass.
     *
     * The accumulator stays small (one entry per email domain), so the
     * memory profile is independent of the record count. SQL GROUP BY
     * would be the production choice for pure aggregation; folding the
     * stream in PHP with Collections is the deliberate exercise of
     * this round.
     *
     * @return array<string, mixed>
     */
    public function report(Import $import): array
    {
        $totals = $this->records($import)->reduce(
            function (array $totals, object $record) {
                // Raw rows arrive as floats from SQLite; the (string)
                // cast is exact here because every amount fits in 12
                // significant digits (decimal(12,6)), within PHP's
                // 14-digit float-to-string round-trip. The toScale
                // call normalizes the scale like the model's decimal:6
                // cast would, so every output has the same format.
                // BigDecimal keeps every following step out of float
                // arithmetic.
                $amount = BigDecimal::of((string) $record->amount)
                    ->toScale(self::SCALE, RoundingMode::HalfUp);

                $totals['count']++;
                $totals['sum'] = $totals['sum']->plus($amount);
                $totals['min'] = $this->min($totals['min'], $amount);
                $totals['max'] = $this->max($totals['max'], $amount);

                // Domains are case-insensitive; local parts are not
                // touched.
                $domain = strtolower((string) str($record->email)->afterLast('@'));

                $bucket = $totals['domains'][$domain] ?? ['count' => 0, 'sum' => BigDecimal::of('0.000000')];
                $bucket['count']++;
                $bucket['sum'] = $bucket['sum']->plus($amount);
                $totals['domains'][$domain] = $bucket;

                return $totals;
            },
            [
                'count' => 0,
                'sum' => BigDecimal::of('0.000000'),
                'min' => null,
                'max' => null,
                'domains' => [],
            ],
        );

        return [
            'import_id' => $import->id,
            'record_count' => $totals['count'],
            'amount' => [
                'sum' => $totals['sum']->__toString(),
                'avg' => $this->average($totals['sum'], $totals['count']),
                'min' => $totals['min']?->__toString(),
                'max' => $totals['max']?->__toString(),
            ],
            'by_email_domain' => collect($totals['domains'])
                ->map(fn (array $bucket, string $domain) => [
                    'domain' => $domain,
                    'count' => $bucket['count'],
                    'sum' => $bucket['sum']->__toString(),
                    'avg' => $this->average($bucket['sum'], $bucket['count']),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
        ];
    }

    /**
     * Streams only the two columns the report needs as raw rows.
     * Profiling on ~283k records showed Eloquent hydration dominating
     * the runtime (67s) while raw cursor rows answer in about a
     * second; precision is unaffected, see the cast note above.
     *
     * @return LazyCollection<int, object>
     */
    private function records(Import $import): LazyCollection
    {
        return DB::table('records')
            ->where('import_id', $import->id)
            ->select(['email', 'amount'])
            ->cursor();
    }

    private function min(?BigDecimal $current, BigDecimal $amount): BigDecimal
    {
        return $current === null || $amount->compareTo($current) < 0 ? $amount : $current;
    }

    private function max(?BigDecimal $current, BigDecimal $amount): BigDecimal
    {
        return $current === null || $amount->compareTo($current) > 0 ? $amount : $current;
    }

    private function average(BigDecimal $sum, int $count): ?string
    {
        if ($count === 0) {
            return null;
        }

        return $sum->dividedBy($count, self::SCALE, RoundingMode::HalfUp)->__toString();
    }
}
