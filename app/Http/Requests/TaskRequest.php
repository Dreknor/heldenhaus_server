<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        if ($task instanceof Task) {
            return $this->user()->can('update', $task);
        }

        return $this->input('scope') !== 'family' || $this->user()->can('createFamilyTask', Task::class);
    }

    public function rules(): array
    {
        $creating = ! $this->route('task') instanceof Task;
        $isFamily = $creating ? $this->input('scope') === 'family' : $this->route('task')->isFamilyTask();

        return [
            'type' => [$creating ? 'required' : 'prohibited', Rule::enum(TaskType::class)],
            'scope' => [$creating ? 'nullable' : 'prohibited', Rule::in(['personal', 'family'])],
            'title' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(array_keys(config('game.categories')))],
            'difficulty' => ['required', Rule::in(array_values(config('game.difficulties')))],
            'up' => ['boolean'],
            'down' => ['boolean'],
            'daily_limit' => ['nullable', 'integer', 'between:1,50'],
            'repeat' => ['nullable', 'array'],
            'repeat.type' => ['required_with:repeat', Rule::in(['daily', 'weekly', 'monthly'])],
            'repeat.every' => ['nullable', 'integer', 'min:1', 'max:365'],
            'repeat.days' => ['nullable', 'array'],
            'repeat.days.*' => ['integer', 'between:1,7'],
            'repeat.day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'checklist' => ['nullable', 'array', 'max:30'],
            'checklist.*' => ['string', 'max:120'],
            'is_private' => ['boolean'],
            'is_core' => ['boolean'],
            'gold_cost' => ['nullable', 'integer', 'min:0', 'max:100000'],
            // nur Familienaufgaben
            'approval_mode' => [$isFamily ? 'required' : 'prohibited', Rule::enum(ApprovalMode::class)],
            'assignment_mode' => [$isFamily ? 'required' : 'prohibited', Rule::in(['assigned', 'pool', 'rotation', 'team'])],
            'rotation_interval' => ['nullable', Rule::in(['daily', 'weekly'])],
            'team_bonus_gold' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'assignees' => ['nullable', 'array'],
            'assignees.*' => ['integer'],
            'star_reward' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ha_event_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'Titel',
            'category' => 'Kategorie',
            'difficulty' => 'Schwierigkeit',
            'assignment_mode' => 'Zuweisung',
            'approval_mode' => 'Freigabe',
        ];
    }
}
