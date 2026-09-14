<?php

namespace App\Services\Ltv;

use App\Services\Timestamp\PadesProfilePolicy;

/**
 * Perfil PAdES EXIBIDO (roadmap T2) diante do estado técnico de longo prazo.
 *
 * O arquivo pode conter, tecnicamente, B-T/B-LT/B-LTA (`ltv_status`). O perfil exibido só deixa
 * de ser o gravado em `signature_profile` (hoje sempre PAdES-B-B) quando `pades_ltv_advertise`
 * estiver ligada — e ligá-la exige TODOS os itens de {@see self::checklist()}. Nenhum caminho
 * produz "ICP-Brasil": o carimbo é da TSA da operadora (T3).
 *
 * Ponto de integração (fora da área P3-LTV): `SignatureNarrative`/`PublicVerification` e a página
 * de evidências devem obter o perfil daqui em vez de ler `signature_profile` direto.
 */
final class LtvProfilePolicy
{
    public static function declaredProfile(): string
    {
        return PadesProfilePolicy::declaredProfile();
    }

    /**
     * @param  string|null  $storedProfile  `verification_records.signature_profile` (nulo sem assinatura criptográfica)
     */
    public static function displayProfile(?string $storedProfile, LtvStatus|string|null $status): ?string
    {
        if ($storedProfile === null || $storedProfile === '') {
            return $storedProfile;
        }

        $status = $status instanceof LtvStatus ? $status : LtvStatus::tryFrom((string) $status) ?? LtvStatus::NotApplicable;

        if (! LtvFeatures::advertise()) {
            return $storedProfile;
        }

        return match ($status) {
            LtvStatus::BT => 'PAdES-B-T',
            LtvStatus::BLt => 'PAdES-B-LT',
            LtvStatus::BLta => 'PAdES-B-LTA',
            LtvStatus::NotApplicable => $storedProfile,
        };
    }

    /**
     * O que precisa estar cumprido (e registrado em arquitetura.md) antes de ligar
     * `pades_ltv_advertise`.
     *
     * @return list<array{item: string, status: string, detail: string}>
     */
    public static function checklist(): array
    {
        return [
            [
                'item' => 'pdftool ltv-validate com fixtures reais',
                'status' => 'parcial',
                'detail' => 'Os testes cobrem B-T, B-LT, B-LTA e o re-carimbo com relógio avançado usando uma PKI de TESTE e CRL/OCSP entregues offline. Faltam fixtures com A1 real, TSA de produção e CRL/OCSP reais das ACs.',
            ],
            [
                'item' => 'Validação externa independente',
                'status' => 'pendente',
                'detail' => 'Abrir as fixtures reais B-T, B-LT e B-LTA (inclusive depois de um re-carimbo) em validador independente — DSS da Comissão Europeia com política customizada confiando na AC interna da TSA, e um leitor PDF de referência — e registrar o resultado por release.',
            ],
            [
                'item' => 'ACT ICP-Brasil, se o anúncio mencionar ICP-Brasil',
                'status' => 'bloqueado',
                'detail' => 'Carimbo ICP-Brasil só com ACT credenciada pelo ITI contratada (viabilidade §4.2 item 7). Até lá o anúncio, se houver, é "carimbo do tempo da operadora — não é ICP-Brasil"; o provedor ICP continua simulador que nunca grava icp_brasil.',
            ],
            [
                'item' => 'Política de assinatura e VRI conferidos',
                'status' => 'pendente',
                'detail' => 'Para qualquer política ICP-Brasil (AD-RT/AD-RA): SignaturePolicyIdentifier da versão vigente na LPA embutido e aprovado no Verificador de Conformidade do ITI; VRI presente no DSS (o pdftool grava e o ltv-validate informa a presença, mas o pyHanko não confere a política — viabilidade §6 item 9).',
            ],
            [
                'item' => 'Verificação pública aceita o histórico de hashes',
                'status' => 'pendente',
                'detail' => 'PublicVerification::checkHash consulta VerificationHashHistory::match() para que um arquivo baixado antes do re-carimbo continue conferindo (decisão de produto, viabilidade §4.5 item 29).',
            ],
            [
                'item' => 'Operação de produção',
                'status' => 'pendente',
                'detail' => 'TSA de produção (checklist do K-TSA), rede de saída do worker até as ACs com revocation_mode hard-fail/require, agendamento de ScheduleArchiveTimestampRefreshes e alerta de falha do re-carimbo.',
            ],
            [
                'item' => 'Decisão registrada em arquitetura.md',
                'status' => 'pendente',
                'detail' => 'Só depois dos itens acima pades_ltv_advertise pode ser ligada.',
            ],
        ];
    }
}
