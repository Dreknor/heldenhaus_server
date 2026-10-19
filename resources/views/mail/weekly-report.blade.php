<x-mail::message>
# Wochenbericht {{ $report['family'] }}

{{ \Carbon\Carbon::parse($report['week_start'])->format('d.m.') }} – {{ \Carbon\Carbon::parse($report['week_end'])->format('d.m.Y') }}

<x-mail::table>
| Wer | Dailies | Aufgaben | Familienaufgaben | Sterne |
|:----|:-------:|:--------:|:----------------:|:------:|
@foreach ($report['members'] as $m)
| {{ $m['name'] }} | {{ $m['dailies_done'] }} / {{ $m['dailies_due'] }} | {{ $m['tasks_done'] }} | {{ $m['family_tasks_done'] }} ({{ $m['family_share'] }} %) | {{ $m['stars'] }} |
@endforeach
</x-mail::table>

@foreach ($report['quests'] as $q)
{{ $q['icon'] }} **{{ $q['name'] }}** – {{ ['completed' => 'geschafft!', 'active' => 'läuft noch', 'expired' => 'abgelaufen', 'abandoned' => 'abgebrochen'][$q['status']] ?? $q['status'] }}
@endforeach

@if ($report['pending_approvals'] > 0)
Es warten noch **{{ $report['pending_approvals'] }} Freigaben** auf euch.
@endif

<x-mail::button :url="$url">Bericht ansehen</x-mail::button>

Viel Spaß weiterhin im Heldenhaus!
</x-mail::message>
