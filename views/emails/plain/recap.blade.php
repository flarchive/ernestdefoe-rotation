<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-rotation.email.recap.body', [
'{time}' => $blueprint->duration(),
'{plays}' => $blueprint->recap['plays'],
]) !!}

@if ($blueprint->recap['artist'])
{!! $translator->trans('ernestdefoe-rotation.email.recap.top_artist', ['{artist}' => $blueprint->recap['artist']]) !!}
@endif
@if ($blueprint->recap['track'])
{!! $translator->trans('ernestdefoe-rotation.email.recap.top_track', ['{track}' => $blueprint->recap['track'], '{artist}' => $blueprint->recap['trackArtist']]) !!}
@endif

{!! $blueprint->profileUrl() !!}
</x-slot:body>
</x-mail::plain.notification>
