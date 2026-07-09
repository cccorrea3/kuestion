<?php

namespace Database\Factories;

use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'user_id' => Str::uuid(),
            'question_text' => fake()->sentence(10) . '?',
            'status' => 'active',
            'is_starred' => fake()->boolean(20),
            'tags' => fake()->randomElements(['rag', 'openai', 'langchain', 'embeddings', 'vector-db', 'prompt', 'fine-tuning', 'chunking'], rand(1, 4)),
            'review_frequency' => fake()->randomElement(['weekly', 'monthly', 'quarterly']),
            'has_unreviewed_changes' => fake()->boolean(15),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'archived']);
    }

    public function starred(): static
    {
        return $this->state(fn () => ['is_starred' => true]);
    }
}
