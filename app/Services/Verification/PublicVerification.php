<?php

namespace App\Services\Verification;

use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\VerificationRecord;

/**
 * Consulta pública por código de verificação (arquitetura §6, ROUTES §4).
 *
 * ## Resposta uniforme
 *
 * Código inexistente, código de rascunho, envelope excluído e registro revogado devolvem
 * **exatamente a mesma** resposta: `found = false`, sem resultado. O motivo é enumeração: o
 * código tem 12 caracteres de um alfabeto de 32 (2^60 combinações), mas se a resposta para um
 * rascunho fosse diferente da resposta para um código inexistente, a página viraria um oráculo
 * que confirma "existe um documento com este código nesta plataforma" — que é justamente o que
 * a política de privacidade (§11) promete não revelar.
 *
 * Pelo mesmo motivo o caminho das duas respostas é o mesmo: uma única consulta indexada por
 * `envelopes.verification_code`, executada **sempre**, mesmo para um código com formato
 * impossível. Não há atalho para o caso "não encontrado" nem consulta extra para o caso
 * "encontrado mas não publicável" — os dois terminam no mesmo `return null`, logo depois da
 * mesma consulta. (Não se adiciona espera artificial: além de não igualar nada de verdade, ela
 * transformaria a rota pública em amplificador de negação de serviço.)
 *
 * ## Estados publicáveis
 *
 * Só envelopes **já enviados**: `in_progress`, `finalizing`, `completed`, `refused`, `expired`
 * e `canceled`. `draft`, `preparing` e `ready` nunca aparecem.
 */
final class PublicVerification
{
    /**
     * Estados que a página pública pode confirmar (ROUTES §4.1).
     *
     * @var list<EnvelopeStatus>
     */
    private const PUBLIC_STATUSES = [
        EnvelopeStatus::InProgress,
        EnvelopeStatus::Finalizing,
        EnvelopeStatus::Completed,
        EnvelopeStatus::Refused,
        EnvelopeStatus::Expired,
        EnvelopeStatus::Canceled,
    ];

    /**
     * Normaliza o código digitado: hifens, espaços e caixa são irrelevantes.
     */
    public function normalize(string $code): string
    {
        return VerificationRecord::normalizeCode($code);
    }

    /**
     * Envelope publicável para o código, ou `null` — sem distinguir "não existe" de
     * "existe mas não é publicável".
     */
    public function lookup(string $code): ?Envelope
    {
        $envelope = Envelope::withoutOrganizationScope()
            ->with([
                'organization',
                'document.originalVersion',
                'sentVersion',
                'finalVersion',
                'recipients',
                'verificationRecord.certificateReference',
            ])
            ->where('verification_code', $this->normalize($code))
            ->first();

        if ($envelope === null) {
            return null;
        }

        $publishable = $envelope->sent_at !== null
            && in_array($envelope->status, self::PUBLIC_STATUSES, true)
            && ! ($envelope->verificationRecord?->isRevoked() ?? false);

        return $publishable ? $envelope : null;
    }

    /**
     * Props do resultado público (ROUTES §2.19 `VerifyShowProps['result']`).
     *
     * Tudo o que entra aqui está na lista do §4.2 e da política de privacidade §11. Nada de
     * e-mail, telefone, IP, user-agent, token, código de acesso, imagem de assinatura, valor
     * de campo, mensagem do remetente, pasta, criador, identificador interno, PDF ou miniatura.
     *
     * @return array<string, mixed>
     */
    public function result(Envelope $envelope): array
    {
        $record = $envelope->verificationRecord;
        $signature = SignatureNarrative::for($envelope, $record);
        $hashes = HashLedger::values($envelope, $record);

        return [
            'verification_code' => $envelope->formatted_verification_code,
            // `finalizing` é detalhe do pipeline: para quem consulta de fora é "em andamento".
            'status' => $envelope->status === EnvelopeStatus::Finalizing
                ? EnvelopeStatus::InProgress->value
                : $envelope->status->value,
            'status_label' => SignatureNarrative::statusLabel($envelope, $record),
            'title' => $envelope->title,
            'organization_name' => $envelope->organization->name,
            'created_at' => $envelope->created_at?->toIso8601String(),
            'sent_at' => $envelope->sent_at?->toIso8601String(),
            'completed_at' => $envelope->completed_at?->toIso8601String(),
            'pages' => $this->pageCount($envelope),
            'hashes' => [
                // Nomes canônicos.
                'sent_sha256' => $hashes['sent'],
                'final_sha256' => $hashes['final'],
                /*
                 * Aliases do contrato atual de `resources/js/types/models.ts`. Atenção:
                 * `original_sha256` carrega o resumo da versão **enviada** (a apresentada aos
                 * signatários), que é o rótulo já usado na página ("Documento enviado"). O
                 * resumo do arquivo original, antes da conversão, NÃO é publicado.
                 */
                'original_sha256' => $hashes['sent'],
                'signed_sha256' => $hashes['final'],
            ],
            'hash_primer' => HashLedger::primer(),
            'signature_status' => $signature['status'],
            'signature_state' => $signature['state'],
            'signature_label' => $signature['label'],
            'signature_statement' => $signature['statement'],
            'signature_profile' => $signature['profile'],
            'certificate' => $signature['certificate'],
            'validation' => $signature['validation'],
            // Frase curta consumida pelo bloco "Sobre a assinatura criptográfica deste arquivo".
            'validation_summary' => $signature['validation']['summary'] ?? null,
            'verify_url' => route('verify.show', ['code' => $envelope->verification_code]),
            'recipients' => $this->recipients($envelope),
            'events_summary' => $this->milestones($envelope, $record, $signature),
        ];
    }

    /**
     * Participantes com nome mascarado (Q13). O e-mail — a chave real do signatário — nunca sai.
     *
     * @return list<array<string, mixed>>
     */
    private function recipients(Envelope $envelope): array
    {
        return array_values($envelope->recipients
            ->map(fn (Recipient $recipient): array => [
                'name_masked' => NameMask::mask($recipient->name),
                // Papel livre digitado pelo remetente ("Locatária", "Fiador"), previsto no §4.2.
                'role' => is_string($recipient->role_label) && trim($recipient->role_label) !== ''
                    ? trim($recipient->role_label)
                    : null,
                'status' => $recipient->status->value,
                'status_label' => $recipient->status->label(),
                'signed_at' => $recipient->signed_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Marcos da linha do tempo. São derivados dos **timestamps do domínio**, não da trilha de
     * auditoria: `audit_events` carrega IP, user-agent e payloads que não podem sair daqui.
     *
     * @param  array<string, mixed>  $signature  saída de {@see SignatureNarrative::for()}
     * @return list<array{label: string, occurred_at: string}>
     */
    private function milestones(Envelope $envelope, ?VerificationRecord $record, array $signature): array
    {
        $milestones = [];

        if ($envelope->sent_at !== null) {
            $milestones[] = ['label' => 'Documento enviado para assinatura', 'occurred_at' => $envelope->sent_at];
        }

        foreach ($envelope->recipients as $recipient) {
            if ($recipient->signed_at !== null) {
                $milestones[] = [
                    'label' => 'Aceite registrado · '.NameMask::mask($recipient->name),
                    'occurred_at' => $recipient->signed_at,
                ];
            }

            if ($recipient->refused_at !== null) {
                $milestones[] = [
                    'label' => 'Recusa registrada · '.NameMask::mask($recipient->name),
                    'occurred_at' => $recipient->refused_at,
                ];
            }
        }

        if ($envelope->refused_at !== null) {
            $milestones[] = ['label' => 'Documento recusado', 'occurred_at' => $envelope->refused_at];
        }

        if ($envelope->expired_at !== null) {
            $milestones[] = ['label' => 'Prazo para assinatura encerrado', 'occurred_at' => $envelope->expired_at];
        }

        if ($envelope->canceled_at !== null) {
            $milestones[] = ['label' => 'Documento cancelado pelo remetente', 'occurred_at' => $envelope->canceled_at];
        }

        if ($record !== null && $record->validated_at !== null && $record->signature_status === SignatureStatus::CompanyA1) {
            /*
             * O rótulo vem do RESULTADO, não do carimbo. `validated_at` diz apenas que a
             * finalização passou pelo passo de validação; se o resultado registrado é
             * ausente, incompleto ou inconclusivo, `SignatureNarrative` já classificou a
             * integridade como `unknown` — e a linha do tempo pública não pode afirmar uma
             * validação que a própria plataforma não afirma (arquitetura §2 e §6). O marco
             * continua visível: o que muda é o que ele diz.
             */
            $validated = (($signature['validation']['integrity'] ?? null) === 'intact');

            $milestones[] = [
                'label' => $validated
                    ? 'Assinatura criptográfica da operadora aplicada e validada'
                    : 'Assinatura criptográfica da operadora aplicada',
                'occurred_at' => $record->validated_at,
            ];
        }

        if ($envelope->completed_at !== null) {
            $milestones[] = ['label' => 'Documento concluído', 'occurred_at' => $envelope->completed_at];
        }

        usort($milestones, fn (array $a, array $b): int => $a['occurred_at']->getTimestamp() <=> $b['occurred_at']->getTimestamp());

        return array_map(
            fn (array $milestone): array => [
                'label' => $milestone['label'],
                'occurred_at' => $milestone['occurred_at']->toIso8601String(),
            ],
            $milestones,
        );
    }

    private function pageCount(Envelope $envelope): int
    {
        return (int) ($envelope->sentVersion->page_count
            ?? $envelope->document->page_count
            ?? 0);
    }

    /**
     * Conferência de um resumo SHA-256 informado por quem consulta.
     *
     * Compara com os resumos **publicados** nesta mesma página — logo, não revela nada novo.
     * `original` significa "corresponde à versão enviada aos signatários, não ao arquivo final".
     *
     * @return array{matches: 'signed'|'original'|'none', checked_sha256: string}
     */
    public function checkHash(Envelope $envelope, string $sha256): array
    {
        $checked = strtolower(trim($sha256));
        $hashes = HashLedger::values($envelope, $envelope->verificationRecord);

        $matches = match (true) {
            $hashes['final'] !== null && hash_equals($hashes['final'], $checked) => 'signed',
            $hashes['sent'] !== null && hash_equals($hashes['sent'], $checked) => 'original',
            default => 'none',
        };

        return ['matches' => $matches, 'checked_sha256' => $checked];
    }
}
