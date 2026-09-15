<?php

use App\Services\Sso\SamlLoginFlow;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/SsoReviewHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão G — replay SAML depois da limpeza: a guarda dura menos que a assertion
|--------------------------------------------------------------------------
| SamlLoginFlow::consume() guarda o ID da assertion até o NotOnOrAfter da
| SubjectConfirmationData (+ margem); SEM esse atributo, guarda por "1 dia". O toolkit
| (onelogin 4.3.2, Response::isValid) aceita SubjectConfirmationData sem NotOnOrAfter e só
| confere as Conditions. Uma assertion IdP-initiated com Conditions de 3 dias volta a entrar
| depois que o `sso:prune` diário apaga o registro. O prazo da guarda deveria ser o MAIOR entre
| Conditions@NotOnOrAfter e SubjectConfirmationData@NotOnOrAfter (ou recusar a falta do SCD).
*/

it('assertion IdP-initiated sem NotOnOrAfter na SubjectConfirmationData entra de novo depois do sso:prune', function () {
    ['organization' => $this->org] = ssoWorkspace();
    $idp = ssoCertificate();
    $connection = ssoSamlConnection($this->org, $idp['certificate'], ['saml_allow_idp_initiated' => true]);
    $member = ssoMember($this->org);
    Http::preventStrayRequests();

    // Conditions válidas por 3 dias; a SubjectConfirmationData sem NotOnOrAfter.
    $xml = ssoSamlXml($connection, ['in_response_to' => null, 'not_on_or_after' => 3 * 86400]);
    $xml = (string) preg_replace('/(<saml:SubjectConfirmationData) NotOnOrAfter="[^"]+"/', '$1', $xml);
    $signed = ssoSamlSign($xml, $idp);

    ssoPostAcs($this, $connection, $signed, null)->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($member);
    auth()->logout();

    // O agendamento diário roda 25 h depois (o toolkit usa time() real: a assertion segue válida).
    $this->travel(25)->hours();
    SamlLoginFlow::prune();
    $this->travelBack();

    ssoPostAcs($this, $connection, $signed, null);

    // Hoje: a mesma assertion autentica de novo.
    $this->assertGuest();
});
