<?php

namespace App\Services;

// ponytail: word-frequency cosine similarity, no embeddings, zero latency.
// Upgrade to embeddings if classification accuracy becomes an issue.
class ChangeDetector
{
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    public function cosineSimilarity(string $old, string $new): float
    {
        $oldFreq = $this->wordFrequency($old);
        $newFreq = $this->wordFrequency($new);

        $allWords = array_unique(array_merge(array_keys($oldFreq), array_keys($newFreq)));

        $vecA = array_map(fn ($w) => $oldFreq[$w] ?? 0, $allWords);
        $vecB = array_map(fn ($w) => $newFreq[$w] ?? 0, $allWords);

        $dot = array_sum(array_map(fn ($a, $b) => $a * $b, $vecA, $vecB));
        $normA = sqrt(array_sum(array_map(fn ($a) => $a * $a, $vecA)));
        $normB = sqrt(array_sum(array_map(fn ($b) => $b * $b, $vecB)));

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return round($dot / ($normA * $normB), 4);
    }

    public function detect(string $old, string $new): array
    {
        $hashChanged = $this->hash($old) !== $this->hash($new);

        if (! $hashChanged) {
            return ['type' => 'unchanged', 'similarity' => 1.0, 'hash_changed' => false];
        }

        $similarity = $this->cosineSimilarity($old, $new);

        if ($similarity >= 0.8) {
            return ['type' => 'minor', 'similarity' => $similarity, 'hash_changed' => true];
        }

        return ['type' => 'new_version', 'similarity' => $similarity, 'hash_changed' => true];
    }

    private function wordFrequency(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        $stopWords = ['de', 'la', 'que', 'el', 'en', 'y', 'a', 'los', 'del', 'se', 'las', 'por', 'un', 'para', 'con', 'no', 'una', 'su', 'al', 'lo', 'como', 'más', 'pero', 'sus', 'le', 'ya', 'este', 'entre', 'porque', 'esta', 'esto', 'ese', 'esa', 'eso', 'tiene', 'sin', 'todo', 'son', 'ser', 'dos', 'también', 'fue', 'era', 'muy', 'solo', 'hay', 'cada', 'así', 'desde', 'hasta', 'cuando', 'donde', 'cual', 'quien', 'nada', 'cómo', 'sino', 'aunque'];

        $freq = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, $stopWords, true)) {
                continue;
            }
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }

        return $freq;
    }
}
