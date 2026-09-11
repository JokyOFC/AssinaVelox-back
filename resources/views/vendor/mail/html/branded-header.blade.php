@props(['brand'])
{{--
    Cabeçalho do e-mail com a marca (mail/branded.blade.php). Faixa na cor de destaque,
    logo (quando houver) e nome de exibição da organização, e "via AssinaVelox" sempre
    visível. Cores já validadas por contraste (App\Services\Branding\ColorContrast).
--}}
<tr>
<td class="header" style="padding: 22px 0 18px 0; text-align: center; border-top: 4px solid {{ $brand['accent_color'] }};">
@if (! empty($brand['logo_url']))
<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['display_name'] }}" height="48" style="height: 48px; max-width: 240px; width: auto; border: 0; display: inline-block;"><br>
@endif
<span style="color: #18181b; font-size: 17px; font-weight: bold; line-height: 1.5;">{{ $brand['display_name'] }}</span><br>
<span style="color: #71717a; font-size: 12px; line-height: 1.5;">via {{ $brand['operator'] }}</span>
</td>
</tr>
