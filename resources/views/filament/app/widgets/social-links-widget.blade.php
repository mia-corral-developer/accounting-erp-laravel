<x-filament-widgets::widget class="fi-social-links-widget">
    <x-filament::section>
        <x-slot name="heading">
            {{ __('Social links') }}
        </x-slot>

        @if (count($links))
            <div class="fi-social-links-widget-links flex flex-wrap gap-x-4 gap-y-2">
                @foreach ($links as $label => $url)
                    <a
                        href="{{ $url }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('No social links configured.') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
