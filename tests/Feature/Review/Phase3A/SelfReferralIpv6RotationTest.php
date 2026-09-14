<?php

use App\Models\Referral;
use App\Services\Affiliates\IpFingerprint;

require_once __DIR__.'/../../Phase3/Affiliates/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — autoindicação contornável trocando o endereço IPv6 da mesma rede
|--------------------------------------------------------------------------
| Attribution::selfReferralReasons() compara o HMAC do IP COMPLETO. Em IPv6 o endereço
| temporário (RFC 8981) muda sozinho, várias vezes por dia, dentro do mesmo /64 do assinante.
| O próprio antifraude trata "mesma rede" como prefixo (SubjectKeys::truncateIp: /48), mas o
| programa de afiliados não: o afiliado se autoindica da MESMA máquina, com o endereço do dia
| seguinte, e a indicação nasce `active`.
*/

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates();
});

test('cadastro da mesma rede IPv6 /64 usada pelo afiliado dentro da janela é autoindicação', function () {
    $affiliate = makeAffiliate([
        'application_ip_hash' => IpFingerprint::of('2001:db8:10:20::10'),
        'last_ip_hash' => IpFingerprint::of('2001:db8:10:20::10'),
        'last_ip_at' => now()->subDay(),
        'terms_accepted_at' => now()->subDays(10),
    ]);

    // Mesmo /64 (mesma rede do assinante), endereço temporário diferente, e-mail de provedor público.
    signupWithReferral('cliente.novo.qualquer@gmail.com', referralCookieValue($affiliate->code), '2001:db8:10:20::11');

    $referral = Referral::query()->sole();

    expect($referral->status)->not->toBe(Referral::STATUS_ACTIVE)
        ->and($referral->block_reasons ?? [])->toContain(Referral::REASON_SAME_IP);
});
