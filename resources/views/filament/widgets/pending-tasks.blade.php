@php($tasks = $this->tasks())

<x-filament-widgets::widget>
    <x-filament::section heading="Perlu dikerjakan" :icon="count($tasks) ? 'heroicon-o-clipboard-document-list' : 'heroicon-o-check-circle'">
        @if (count($tasks))
            <ul style="display: grid; gap: .375rem; font-size: .875rem">
                @foreach ($tasks as $task)
                    <li style="display: flex; gap: .5rem; align-items: baseline">
                        <span style="color: var(--warning-600)">•</span>
                        @if ($task['url'])
                            <a href="{{ $task['url'] }}" style="color: var(--primary-600); text-decoration: underline">{{ $task['text'] }}</a>
                        @else
                            <span>{{ $task['text'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p style="font-size: .875rem; color: var(--gray-500)">Nothing pending.</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
