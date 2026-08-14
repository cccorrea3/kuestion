<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Database\Eloquent\Builder;

// ponytail: in-memory matching for < 1000 questions. Tag match has 3x weight.
// Keywords extracted from text, stopwords filtered. No embeddings, no API calls.
// Upgrade to FULLTEXT index or embeddings if question count exceeds ~1000.
class RelationSuggester
{
    private array $stopWords = [
        'de', 'la', 'que', 'el', 'en', 'y', 'a', 'los', 'del', 'se', 'las',
        'por', 'un', 'para', 'con', 'no', 'una', 'su', 'al', 'lo', 'como',
        'más', 'pero', 'sus', 'le', 'ya', 'este', 'entre', 'porque', 'este',
        'esta', 'esto', 'ese', 'esa', 'eso', 'tiene', 'sin', 'todo', 'son',
        'ser', 'dos', 'también', 'fue', 'era', 'muy', 'solo', 'hay', 'cada',
        'así', 'desde', 'hasta', 'cuando', 'donde', 'cual', 'quien', 'nada',
        'cómo', 'sino', 'aunque',
    ];

    public function suggest(string $text, array $tags, string $userId, ?string $excludeId = null): array
    {
        $keywords = $this->extractKeywords($text);
        $tags = array_map('strtolower', $tags);
        $excludeId = $excludeId ?: '';

        $query = Question::where('user_id', $userId)
            ->where('status', 'active')
            ->where('id', '!=', $excludeId);

        // Pre-filtro FULLTEXT: restringe candidatos a los que contienen al menos una keyword.
        // Si el tokenizador de MySQL descarta el término completo (stopwords), se cae a
        // escaneo completo para no perder candidatos (el scoring con tags sigue igual).
        if (! empty($keywords) && $this->fullTextMatches($query, $keywords)) {
            $query->whereFullText('question_text', implode(' ', $keywords));
        }

        $candidates = $query->get(['id', 'question_text', 'tags']);

        $results = [];

        foreach ($candidates as $candidate) {
            $candidateTags = array_map('strtolower', (array) ($candidate->tags ?? []));
            $tagMatches = array_intersect($tags, $candidateTags);
            $tagScore = count($tagMatches) * 3;

            $candidateKeywords = $this->extractKeywords($candidate->question_text);
            $keywordMatches = array_intersect($keywords, $candidateKeywords);
            $keywordScore = count($keywordMatches);

            $score = $tagScore + $keywordScore;
            if ($score <= 0) {
                continue;
            }

            $results[] = [
                'id' => $candidate->id,
                'question_text' => $candidate->question_text,
                'score' => $score,
                'matched_tags' => array_values($tagMatches),
                'matched_keywords' => array_values($keywordMatches),
            ];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, 10);
    }

    /**
     * Verifica si el conjunto de keywords produce coincidencias en modo natural
     * (si MySQL las descarta por completo, el pre-filtro se omite).
     */
    private function fullTextMatches(Builder $query, array $keywords): bool
    {
        return $query->clone()
            ->whereFullText('question_text', implode(' ', $keywords))
            ->exists();
    }

    private function extractKeywords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && ! in_array($word, $this->stopWords, true)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }
}
