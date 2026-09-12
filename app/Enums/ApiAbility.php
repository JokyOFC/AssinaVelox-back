<?php

namespace App\Enums;

/**
 * Abilities (escopos) dos tokens da API v1 — conjunto FECHADO (docs/fase-2/api-v1.md §3).
 *
 * Um token só faz o que as abilities dele permitem E o que o usuário que o criou pode fazer
 * na organização no momento da chamada (as Policies continuam decidindo). Nunca há `*`.
 *
 * `requiredPermissions()` é a trava anti-escalada da EMISSÃO: ninguém cria um token com uma
 * ability cujas permissões não tem. A chamada confere de novo, pelas Policies, com as
 * permissões atuais do criador — perder a permissão depois desliga a ability na hora.
 *
 * Os valores são estáveis (gravados em `personal_access_tokens.abilities`): nunca renomeie.
 */
enum ApiAbility: string
{
    case EnvelopesRead = 'envelopes:read';
    case EnvelopesWrite = 'envelopes:write';
    case EnvelopesSend = 'envelopes:send';
    case DocumentsRead = 'documents:read';
    case RecipientsRead = 'recipients:read';
    case TemplatesRead = 'templates:read';
    case TemplatesUse = 'templates:use';
    case WebhooksManage = 'webhooks:manage';

    public function label(): string
    {
        return match ($this) {
            self::EnvelopesRead => 'Consultar documentos',
            self::EnvelopesWrite => 'Criar e preparar documentos',
            self::EnvelopesSend => 'Enviar e cancelar documentos',
            self::DocumentsRead => 'Baixar arquivos',
            self::RecipientsRead => 'Consultar participantes',
            self::TemplatesRead => 'Consultar modelos',
            self::TemplatesUse => 'Gerar documentos a partir de modelos',
            self::WebhooksManage => 'Gerenciar webhooks',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EnvelopesRead => 'Listar e detalhar documentos, campos, eventos da trilha e o registro de verificação.',
            self::EnvelopesWrite => 'Criar rascunhos, enviar arquivos e definir participantes e campos.',
            self::EnvelopesSend => 'Enviar para assinatura e cancelar documentos em andamento.',
            self::DocumentsRead => 'Baixar o arquivo original, o arquivo final e a página de evidências.',
            self::RecipientsRead => 'Consultar a situação de cada participante.',
            self::TemplatesRead => 'Listar modelos e ver as variáveis e os papéis de cada um.',
            self::TemplatesUse => 'Gerar um documento em rascunho a partir de um modelo.',
            self::WebhooksManage => 'Cadastrar e remover assinaturas de webhooks.',
        };
    }

    /**
     * Permissões que o CRIADOR precisa ter para conceder esta ability a um token.
     *
     * @return list<Permission>
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::EnvelopesRead,
            self::DocumentsRead,
            self::RecipientsRead,
            self::TemplatesRead => [],
            self::EnvelopesWrite,
            self::TemplatesUse => [Permission::CreateEnvelopes],
            self::EnvelopesSend => [Permission::SendEnvelopes],
            self::WebhooksManage => [Permission::ManageIntegrations],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
