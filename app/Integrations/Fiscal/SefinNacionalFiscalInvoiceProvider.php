<?php

namespace App\Integrations\Fiscal;

use App\Integrations\Contracts\FiscalInvoiceProvider;

/**
 * Adaptador do Sistema Nacional NFS-e (Emissor Público Nacional — Sefin Nacional + ADN) —
 * **DESABILITADO** (roadmap §2.21, classe B; docs/integracoes/nfse.md §3 e §7).
 *
 * O caminho oficial existe e está documentado (`POST /nfse` síncrono a partir da DPS,
 * `GET|HEAD /dps/{id}` para "consultar antes de reemitir", `POST /nfse/{chave}/eventos` para
 * cancelar), mas a emissão real depende de dados e credenciais que o software não pode decidir
 * nem inventar. Até lá, este adaptador não abre conexão nenhuma: `isConfigured()` é sempre
 * falso e toda operação lança `FiscalProviderUnavailable` com a lista do que falta.
 *
 * O envelope JSON exato do `POST /nfse`, os códigos de erro e a URL base de cada operação estão
 * NÃO CONFIRMADOS (Swagger oficial bloqueado ao acesso anônimo na pesquisa) — por isso não há
 * uma linha de HTTP aqui.
 */
final class SefinNacionalFiscalInvoiceProvider implements FiscalInvoiceProvider
{
    public const NAME = 'sefin_nacional';

    /** Pendências da operadora para desbloquear a emissão (docs/integracoes/nfse.md §7). */
    public const MISSING = [
        'CNPJ, razão social, município (código IBGE) e regime tributário da operadora.',
        'Confirmação de que o município emite pelo Emissor Nacional (GET /parametros_municipais/{codigoMunicipio}/convenio) e cadastro da operadora no CNC.',
        'Certificado digital da operadora para TLS mútuo (mTLS) e assinatura XMLDSig da DPS (kind=fiscal_a1, separado do A1 do PAdES), com o tipo aceito confirmado no Swagger/FAQ oficial.',
        'Parecer contábil: subitem da LC 116/2003, código NBS, alíquota e retenções de ISS e tratamento de IBS/CBS em 2026/2027.',
        'Escolha da biblioteca de XMLDSig e validação contra o XSD v1.01.',
        'Rodada completa em produção restrita (emissão, rejeição, timeout seguido de consulta, cancelamento) com fixtures gravadas.',
    ];

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function issue(array $invoice, ?string $correlationId = null): array
    {
        throw FiscalProviderUnavailable::sefinDisabled();
    }

    public function cancel(string $invoiceId, string $reason, ?string $correlationId = null): array
    {
        throw FiscalProviderUnavailable::sefinDisabled();
    }
}
