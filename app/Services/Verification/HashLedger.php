<?php

namespace App\Services\Verification;

use App\Models\Envelope;
use App\Models\VerificationRecord;

/**
 * Os quatro resumos SHA-256 do envelope e o que **cada um** identifica
 * (docs/juridico/declaracao-de-aceite.md §5.1).
 *
 * A regra que este serviço protege é a da arquitetura §5 item 7 e do pdf-pipeline: o resumo
 * **final** é calculado depois da assinatura criptográfica e vive fora do PDF, em
 * `verification_records`. Um hash não pode estar contido no arquivo que ele identifica.
 *
 * Fonte preferida: `verification_records`, gravado pela finalização. Enquanto o envelope não
 * concluiu, os valores conhecidos vêm das próprias versões do documento (`original` e a
 * congelada no envio); consolidado e final ficam nulos, porque não existem.
 *
 * Exposição:
 *
 * - página **pública** de verificação: somente `sent` e `final` (política de privacidade §11 e
 *   arquitetura §6). O resumo `original` identifica os bytes do arquivo como a organização o
 *   enviou — DOCX, imagem, PDF antes da conversão — e não interessa a terceiros; o
 *   `consolidated` é um artefato interno do pipeline;
 * - página **de evidências** (autenticada, para quem já pode ver o documento): os quatro,
 *   como o relatório impresso.
 */
final class HashLedger
{
    /**
     * Todos os resumos conhecidos, na ordem do pipeline, com rótulo e explicação.
     *
     * @return list<array{key: string, label: string, value: string|null, description: string}>
     */
    public static function items(Envelope $envelope, ?VerificationRecord $record): array
    {
        $values = self::values($envelope, $record);

        return [
            [
                'key' => 'original',
                'label' => 'Original',
                'value' => $values['original'],
                'description' => 'Dos bytes do arquivo exatamente como a organização remetente o '
                    .'enviou à plataforma (PDF, DOCX ou imagem), antes de qualquer conversão.',
            ],
            [
                'key' => 'sent',
                'label' => 'Enviado',
                'value' => $values['sent'],
                'description' => 'Dos bytes da versão em PDF congelada no envio e apresentada a '
                    .'todos os signatários. É este resumo que cada declaração de aceite referencia. '
                    .'Se o original já era um PDF sem conversão, pode coincidir com o resumo original.',
            ],
            [
                'key' => 'consolidated',
                'label' => 'Consolidado',
                'value' => $values['consolidated'],
                'description' => 'Dos bytes do PDF gerado após a coleta, com os campos preenchidos e '
                    .'as representações visuais de assinatura achatadas nas páginas, antes do '
                    .'acréscimo do relatório de evidências.',
            ],
            [
                'key' => 'final',
                'label' => 'Final',
                'value' => $values['final'],
                'description' => 'Dos bytes do arquivo final completo (documento consolidado + '
                    .'relatório de evidências + assinatura criptográfica da operadora, quando aplicada). '
                    .'É calculado depois de o arquivo estar pronto e por isso não pode constar dentro '
                    .'dele: é publicado apenas na página de verificação. Para conferir o arquivo que '
                    .'você tem em mãos, calcule o SHA-256 dele e compare com este valor.',
            ],
        ];
    }

    /**
     * Valores crus por chave. `null` quando o artefato ainda não existe.
     *
     * @return array{original: string|null, sent: string|null, consolidated: string|null, final: string|null}
     */
    public static function values(Envelope $envelope, ?VerificationRecord $record): array
    {
        $document = $envelope->document;

        // `$record->…` sem nullsafe é intencional: o `??` já absorve o acesso quando o registro
        // não existe, e é a forma que a análise estática exige aqui.
        return [
            'original' => self::clean($record->original_sha256 ?? $document?->originalVersion?->sha256),
            'sent' => self::clean($record->sent_sha256 ?? $envelope->sentVersion?->sha256),
            'consolidated' => self::clean($record?->consolidated_sha256),
            'final' => self::clean($record->final_sha256 ?? $envelope->finalVersion?->sha256),
        ];
    }

    /**
     * Texto de apoio comum às duas páginas — o que um resumo é e o que ele não é.
     */
    public static function primer(): string
    {
        return 'Um resumo SHA-256 é uma sequência de 64 caracteres que identifica um arquivo byte a '
            .'byte: qualquer alteração, por menor que seja, produz um resumo completamente diferente. '
            .'Um resumo não é uma assinatura — ele permite conferir se dois arquivos são idênticos, e '
            .'nada mais.';
    }

    private static function clean(?string $hash): ?string
    {
        $hash = strtolower(trim((string) $hash));

        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }
}
