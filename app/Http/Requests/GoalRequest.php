<?php

namespace App\Http\Requests;

use App\Models\Goal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ziel anlegen oder ändern (§14.1); die Spielregeln (max. 3, Warum, Arten) prüft der GoalService. */
class GoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'user_id' => ['nullable', 'integer'],
            'type' => [$creating ? 'required_without:template_key' : 'prohibited', 'nullable', Rule::in(array_keys(Goal::TYPES))],
            'template_key' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:80'],
            'title' => [$creating ? 'required_without:template_key' : 'sometimes', 'nullable', 'string', 'max:120'],
            'why' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'icon' => ['nullable', 'string', 'max:16'],
            'category' => ['nullable', Rule::in(array_keys(config('game.categories')))],
            'target' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'milestones' => ['nullable', 'array', 'max:5'],
            'milestones.*' => ['string', 'max:120'],
            'task_ids' => ['nullable', 'array', 'max:5'],
            'task_ids.*' => ['integer'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:today'],
            'is_private' => ['boolean'],
            'reward_stars' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'reward_wish' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'Titel', 'why' => 'Warum', 'target' => 'Ziel', 'ends_on' => 'Enddatum'];
    }
}
