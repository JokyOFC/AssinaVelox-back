<?php

use App\Services\Signing\SignerRequestFacts;
use App\Support\IpDisplay;

/*
|--------------------------------------------------------------------------
| Revisão de design — duas máscaras diferentes para o mesmo IP
|--------------------------------------------------------------------------
| `App\Support\IpDisplay` nasceu justamente para unificar a exibição do IP em
| todas as telas; o próprio docblock da classe registra o problema ("Na mesma
| página o operador via 127.0.***.*** num lugar e 127.0.0.1 no outro") e explica
| por que esconde DOIS octetos ("Um IP com apenas o último octeto escondido
| continua identificando a rede /24 — para minimização de dado pessoal isso é
| pouco").
|
| A tela do signatário não passa por ela: `SignerRequestFacts::displayIp()` tem
| máscara própria, que esconde só o último octeto e usa outro glifo. O mesmo
| aceite aparece como `127.0.***.***` no detalhe do documento e `127.0.0.•••` no
| comprovante do signatário — e é justamente na tela pública, a que o titular do
| dado vê, que a minimização é a mais fraca das duas.
*/

it('usa a mesma máscara de IP no comprovante do signatário e nas telas do remetente (IPv4)', function () {
    $ip = '203.0.113.45';

    expect(SignerRequestFacts::displayIp($ip, 'masked'))->toBe(IpDisplay::mask($ip));
});

it('usa a mesma máscara de IP no comprovante do signatário e nas telas do remetente (IPv6)', function () {
    $ip = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

    expect(SignerRequestFacts::displayIp($ip, 'masked'))->toBe(IpDisplay::mask($ip));
});
