<x-mail::html.notification>
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-rotation.email.recap.body', ['{time}' => $blueprint->duration(), '{plays}' => $blueprint->recap['plays']]) }}</p>
        @if ($blueprint->recap['artist'])
            <p>{{ $translator->trans('ernestdefoe-rotation.email.recap.top_artist', ['{artist}' => $blueprint->recap['artist']]) }}</p>
        @endif
        @if ($blueprint->recap['track'])
            <p>{{ $translator->trans('ernestdefoe-rotation.email.recap.top_track', ['{track}' => $blueprint->recap['track'], '{artist}' => $blueprint->recap['trackArtist']]) }}</p>
        @endif
        <p><a href="{{ $blueprint->profileUrl() }}">{{ $translator->trans('ernestdefoe-rotation.email.recap.link') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
