@props([
    'url',
    'color' => '#1257C9',
    'textColor' => '#FFFFFF',
    'align' => 'center',
])
{{-- Botão do e-mail com a cor primária da marca (contraste com o texto branco ≥ 4,5:1). --}}
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button" target="_blank" rel="noopener" style="background-color: {{ $color }}; border-bottom: 8px solid {{ $color }}; border-left: 18px solid {{ $color }}; border-right: 18px solid {{ $color }}; border-top: 8px solid {{ $color }}; color: {{ $textColor }};">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
