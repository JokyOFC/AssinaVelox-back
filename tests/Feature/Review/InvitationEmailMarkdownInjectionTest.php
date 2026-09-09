<?php

use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Envelopes\RecipientInvitationNotification;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final de segurança — injeção de Markdown no e-mail de convite
|--------------------------------------------------------------------------
| `envelopes.message` é a "Mensagem para os signatários" do wizard: um textarea livre,
| validado apenas como `string|max:1000` (UpdateEnvelopeRequest:41). Nada na interface
| nem na documentação diz que ele aceita marcação.
|
| `RecipientInvitationNotification::toMail()` (linha 78) interpola o valor cru em
| `MailMessage::line()`. As linhas do MailMessage passam por
| `Illuminate\Mail\Markdown` antes de virar HTML: o Blade escapa o HTML bruto
| (`<img onerror=…>` sai como texto), mas a SINTAXE MARKDOWN continua ativa — em
| particular `[texto](url)`, que vira uma âncora de verdade.
|
| O resultado é um link arbitrário, com texto arbitrário, dentro de um e-mail que sai do
| domínio da plataforma, com o SPF/DKIM/DMARC da plataforma, no mesmo parágrafo do botão
| legítimo "Revisar e assinar". Qualquer conta — inclusive uma conta Grátis recém-criada
| — pode usar a reputação de envio da AssinaVelox para entregar um link de phishing a
| endereços que ela escolhe.
|
| O mesmo caminho vale para `envelopes.title`, interpolado em negrito na mesma linha.
|
| Os dois testes abaixo falham hoje.
*/

beforeEach(fn () => $this->withoutVite());

function invitationMailHtml(string $message = '', ?string $title = null): string
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(
        array_filter(['message' => $message, 'title' => $title]),
    );

    $recipient = Recipient::factory()->forEnvelope($envelope)->create();

    $notification = new RecipientInvitationNotification(
        $recipient,
        $envelope->fresh(),
        'https://app.test/assinar/'.str_repeat('a', 43),
        'corr-review',
    );

    return (string) $notification->toMail($recipient)->render();
}

it('não transforma a mensagem do remetente em link no e-mail de convite', function () {
    $html = invitationMailHtml('[Confirme seus dados bancarios](https://evil.example/phish)');

    expect($html)->toContain('Mensagem de quem enviou')
        ->and($html)->not->toContain('href="https://evil.example/phish"');
});

it('não transforma o título do documento em link no e-mail de convite', function () {
    $html = invitationMailHtml('', '[Contrato de locacao](https://evil.example/phish)');

    expect($html)->not->toContain('href="https://evil.example/phish"');
});
