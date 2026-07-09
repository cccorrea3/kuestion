<?php

namespace App\Services;

// ponytail: positional line diff, not LCS. Good enough for single-user MVP.
// similar_text O(n²) — fine for typical answers. Upgrade to LCS or Myers if reordering or
// long texts become an issue.
class DiffGenerator
{
    public function diff(string $old, string $new): array
    {
        similar_text($old, $new, $percent);

        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $lines = [];
        $max = max(count($oldLines), count($newLines));

        for ($i = 0; $i < $max; $i++) {
            $oL = $oldLines[$i] ?? null;
            $nL = $newLines[$i] ?? null;

            if ($oL === null) {
                $lines[] = ['type' => 'added', 'text' => $nL];
            } elseif ($nL === null) {
                $lines[] = ['type' => 'removed', 'text' => $oL];
            } elseif ($oL === $nL) {
                $lines[] = ['type' => 'unchanged', 'text' => $oL];
            } else {
                $lines[] = ['type' => 'changed', 'old' => $oL, 'new' => $nL];
            }
        }

        return [
            'unchanged' => $old === $new,
            'similarity' => round($percent, 1),
            'lines' => $lines,
        ];
    }
}
