{{--
    Tema do e-mail ao participante com a marca da organização (Fase 2 §2.8,
    docs/fase-2/branding.md §5). Usado SÓ quando a marca está ativa
    (App\Notifications\Concerns\AppliesOrganizationBranding); sem ela vale o
    `notifications::email` do Laravel, como na Fase 1.

    O CORPO é o mesmo do `notifications::email`: saudação, linhas, botão, linhas finais,
    assinatura e o texto alternativo do link — nada do texto da notificação muda. A marca
    entra no cabeçalho (logo e nome), na cor do botão e no rodapé, que mantém
    "via AssinaVelox": a operadora não some.

    Sem pixel de rastreamento: a única imagem é o logo, na mesma URL para todos os
    destinatários (só o token opaco da versão do logo, nada da pessoa ou da mensagem).
--}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::branded-header :brand="$brand" />
</x-slot:header>

{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<x-mail::branded-button :url="$actionUrl" :color="$brand['primary_color']" :text-color="$brand['on_primary']">
{{ $actionText }}
</x-mail::branded-button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ $brand['display_name'] }} · enviado via {{ $brand['operator'] }}

© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
