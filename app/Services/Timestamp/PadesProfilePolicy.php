<?php

namespace App\Services\Timestamp;

/**
 * Perfil PAdES ANUNCIADO (roadmap T2): continua `PAdES-B-B` mesmo quando a flag `pades_bt`
 * embute o carimbo da TSA da operadora na assinatura (o que, tecnicamente, é B-T no sentido
 * ETSI). Nenhum código deste repositório pode gravar `signature_profile = PAdES-B-T` antes
 * de TODOS os itens de {@see self::checklist()} estarem cumpridos e registrados em
 * `arquitetura.md`.
 */
final class PadesProfilePolicy
{
    public const DECLARED_PROFILE = 'PAdES-B-B';

    /** O nível técnico que o pdftool embute com `--tsa-*` — NÃO anunciado. */
    public const UNANNOUNCED_TECHNICAL_LEVEL = 'PAdES-B-T';

    public static function declaredProfile(): string
    {
        return self::DECLARED_PROFILE;
    }

    /**
     * O que falta para anunciar B-T (roadmap T2 + §3.6 aceite; carimbo-do-tempo-e-ltv §4).
     *
     * @return list<array{item: string, status: string, detail: string}>
     */
    public static function checklist(): array
    {
        return [
            [
                'item' => 'pdftool validate (pyHanko) com fixtures reais',
                'status' => 'parcial',
                'detail' => 'Testes automatizados validam a assinatura e o carimbo embutido com certificados de TESTE; faltam fixtures com certificado A1 real e a TSA de produção.',
            ],
            [
                'item' => 'Validação externa independente',
                'status' => 'pendente',
                'detail' => 'Abrir o PDF B-T em validador independente (DSS da Comissão Europeia com política customizada que confie na AC interna, e leitor PDF de referência) e registrar o resultado por release. O Verificador do ITI tende a responder Indeterminado para carimbo "operator" (âncora não ICP-Brasil).',
            ],
            [
                'item' => 'TSA de produção',
                'status' => 'pendente',
                'detail' => 'Chave em HSM/KMS, NTP monitorado com recusa (timeNotAvailable) fora da precisão declarada, OID de política próprio e certificado da AC interna com EKU timeStamping crítica.',
            ],
            [
                'item' => 'Integração na finalização',
                'status' => 'pendente',
                'detail' => 'OperatorSignature/participante chamam PadesBtSigner atrás da flag, com degradação explícita para B-B registrada quando a TSA falhar.',
            ],
            [
                'item' => 'Decisão registrada em arquitetura.md',
                'status' => 'pendente',
                'detail' => 'Só depois dos itens acima o valor PAdES-B-T pode entrar em verification_records.signature_profile e na interface.',
            ],
        ];
    }
}
