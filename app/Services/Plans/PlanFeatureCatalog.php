<?php

namespace App\Services\Plans;

use App\Http\Controllers\Billing\PlanController;
use App\Models\Plan;

/**
 * Catálogo das chaves booleanas de `plans.features` que a plataforma entende — a lista que o
 * painel interno (Admin\PlanController) oferece como interruptores por plano.
 *
 * Duas famílias:
 *
 *  - **comerciais**: só aparecem como itens do plano nas telas de preço (site e
 *    `plans.index`), via {@see PlanController::featureLabels()}; nenhum serviço as lê;
 *  - **com interruptor global**: o recurso só existe quando `config('assinavelox.features.{key}')`
 *    E a chave do plano dizem sim (roadmap §1 T8). O painel mostra o estado do interruptor ao
 *    lado do toggle, para ninguém "vender" um recurso que a instalação mantém desligado.
 *
 * Chaves NÃO booleanas (`storage_bytes`, `bulk_generation_limits`) não entram aqui: o
 * armazenamento tem campo próprio na tela e o restante é preservado como está ao salvar.
 */
final class PlanFeatureCatalog
{
    /**
     * @return list<array{key: string, group: string, label: string, description: string}>
     */
    public static function entries(): array
    {
        return [
            // Comerciais — só rótulo nas telas de preço.
            ['key' => 'email_otp', 'group' => 'commercial', 'label' => 'Código por e-mail', 'description' => 'Confirmação do signatário por código enviado ao e-mail.'],
            ['key' => 'evidence_page', 'group' => 'commercial', 'label' => 'Página de evidências', 'description' => 'Relatório de evidências anexado ao documento final.'],
            ['key' => 'folders', 'group' => 'commercial', 'label' => 'Pastas', 'description' => 'Organização dos documentos em pastas.'],
            ['key' => 'priority_support', 'group' => 'commercial', 'label' => 'Suporte prioritário', 'description' => 'Atendimento com prioridade na fila de suporte.'],
            ['key' => 'api', 'group' => 'commercial', 'label' => 'API e webhooks', 'description' => 'Rótulo comercial; o acesso real depende de "API REST" e "Webhooks" abaixo.'],

            // Documentos e fluxo.
            ['key' => 'templates', 'group' => 'documents', 'label' => 'Modelos', 'description' => 'Modelos reutilizáveis de documentos.'],
            ['key' => 'multi_document', 'group' => 'documents', 'label' => 'Vários arquivos por envelope', 'description' => 'Mais de um documento na mesma solicitação.'],
            ['key' => 'participant_roles', 'group' => 'documents', 'label' => 'Papéis de participante', 'description' => 'Aprovador e visualizador além do signatário.'],
            ['key' => 'reminders', 'group' => 'documents', 'label' => 'Lembretes e envio agendado', 'description' => 'Lembretes automáticos e agendamento do envio.'],
            ['key' => 'conditional_steps', 'group' => 'documents', 'label' => 'Etapas condicionais', 'description' => 'Ordem de assinatura com etapas condicionais.'],
            ['key' => 'delegation', 'group' => 'documents', 'label' => 'Delegação de assinatura', 'description' => 'O signatário pode delegar a outra pessoa.'],
            ['key' => 'field_anchors', 'group' => 'documents', 'label' => 'Âncoras de campo', 'description' => 'Campos sugeridos por âncoras no PDF.'],
            ['key' => 'ocr', 'group' => 'documents', 'label' => 'OCR', 'description' => 'Reconhecimento de texto para âncoras em arquivos digitalizados.'],
            ['key' => 'bulk_generation', 'group' => 'documents', 'label' => 'Geração em lote', 'description' => 'Documentos gerados a partir de modelo e planilha.'],
            ['key' => 'cloud_import', 'group' => 'documents', 'label' => 'Importação da nuvem', 'description' => 'Arquivos importados de serviços de armazenamento.'],
            ['key' => 'multilingual', 'group' => 'documents', 'label' => 'Multilíngue', 'description' => 'Fluxo do signatário em outros idiomas.'],

            // Assinatura e identidade.
            ['key' => PlanFeatures::COMPANY_SIGNATURE, 'group' => 'signing', 'label' => 'Assinatura criptográfica da operadora', 'description' => 'PAdES aplicado pela operadora ao arquivo final. Só é anunciada quando há certificado ativo.'],
            ['key' => 'participant_a1', 'group' => 'signing', 'label' => 'Certificado A1 do participante', 'description' => 'Assinatura com o certificado do próprio participante.'],
            ['key' => 'a3_signing', 'group' => 'signing', 'label' => 'Certificado A3 (componente local)', 'description' => 'Assinatura com token ou cartão, fora da plataforma.'],
            ['key' => 'govbr_return', 'group' => 'signing', 'label' => 'Devolução assinada no gov.br', 'description' => 'Participante devolve o arquivo assinado no portal gov.br.'],
            ['key' => 'sms_whatsapp', 'group' => 'signing', 'label' => 'SMS e WhatsApp', 'description' => 'Convites e códigos por SMS e WhatsApp.'],
            ['key' => 'pin_auth', 'group' => 'signing', 'label' => 'PIN do signatário', 'description' => 'Autenticação adicional por PIN.'],
            ['key' => 'sender_domains', 'group' => 'signing', 'label' => 'Domínios de envio', 'description' => 'E-mails enviados pelo domínio da organização.'],
            ['key' => 'in_person', 'group' => 'signing', 'label' => 'Assinatura presencial', 'description' => 'Coleta presencial em tablet.'],
            ['key' => 'batch_signing', 'group' => 'signing', 'label' => 'Assinatura em lote', 'description' => 'Vários documentos assinados de uma vez.'],
            ['key' => 'identity_capture', 'group' => 'signing', 'label' => 'Captura de identidade', 'description' => 'Foto e documento do signatário no aceite.'],
            ['key' => 'identity_video', 'group' => 'signing', 'label' => 'Vídeo no aceite', 'description' => 'Vídeo curto do signatário no aceite.'],
            ['key' => 'identity_verification', 'group' => 'signing', 'label' => 'Verificação facial', 'description' => 'Comparação facial com documento por provedor externo.'],
            ['key' => 'cpf_field', 'group' => 'signing', 'label' => 'CPF do signatário', 'description' => 'Campo de CPF no fluxo do signatário.'],
            ['key' => 'cpf_lookup', 'group' => 'signing', 'label' => 'Consulta de CPF', 'description' => 'Validação do CPF informado.'],
            ['key' => 'cnpj_lookup', 'group' => 'signing', 'label' => 'Consulta de CNPJ', 'description' => 'Autopreenchimento pelo CNPJ.'],

            // Organização.
            ['key' => 'branding', 'group' => 'organization', 'label' => 'Logo da empresa', 'description' => 'Marca da organização nos e-mails e na página pública.'],
            ['key' => 'custom_roles', 'group' => 'organization', 'label' => 'Funções personalizadas', 'description' => 'Permissões por função além de admin/membro.'],
            ['key' => 'tags', 'group' => 'organization', 'label' => 'Etiquetas', 'description' => 'Etiquetas nos documentos.'],
            ['key' => 'reports', 'group' => 'organization', 'label' => 'Relatórios', 'description' => 'Relatórios e exportações.'],
            ['key' => 'audit_log', 'group' => 'organization', 'label' => 'Log de auditoria', 'description' => 'Trilha de ações da organização.'],
            ['key' => 'retention_policies', 'group' => 'organization', 'label' => 'Políticas de retenção', 'description' => 'Exclusão programada e preservação legal.'],
            ['key' => 'dossier_export', 'group' => 'organization', 'label' => 'Dossiê ZIP', 'description' => 'Exportação do dossiê completo do documento.'],
            ['key' => 'public_forms', 'group' => 'organization', 'label' => 'Formulários públicos', 'description' => 'Formulário público que gera documentos a partir de modelo.'],
            ['key' => 'embedded_signing', 'group' => 'organization', 'label' => 'Widget embutido', 'description' => 'Assinatura dentro do site do cliente.'],

            // Integrações.
            ['key' => 'api_integrations', 'group' => 'integrations', 'label' => 'API REST', 'description' => 'Tokens e API v1.'],
            ['key' => 'outbound_webhooks', 'group' => 'integrations', 'label' => 'Webhooks', 'description' => 'Notificações de eventos para URLs do cliente.'],
            ['key' => 'rest_hooks', 'group' => 'integrations', 'label' => 'REST Hooks (n8n, Zapier, Make)', 'description' => 'Assinatura dinâmica de webhooks pela API.'],
            ['key' => 'hubspot', 'group' => 'integrations', 'label' => 'HubSpot', 'description' => 'Conector com o HubSpot.'],
            ['key' => 'sso_oidc', 'group' => 'integrations', 'label' => 'SSO OIDC', 'description' => 'Login corporativo por OpenID Connect.'],
            ['key' => 'sso_saml', 'group' => 'integrations', 'label' => 'SSO SAML', 'description' => 'Login corporativo por SAML.'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'commercial' => 'Itens comerciais',
            'documents' => 'Documentos e fluxo',
            'signing' => 'Assinatura e identidade',
            'organization' => 'Organização',
            'integrations' => 'Integrações',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::entries(), 'key');
    }

    /**
     * Entradas com o estado do interruptor global da instalação (`null` para as comerciais),
     * na forma que o painel interno consome.
     *
     * @return list<array{key: string, group: string, label: string, description: string, global_enabled: bool|null}>
     */
    public static function forAdmin(): array
    {
        $globals = (array) config('assinavelox.features', []);

        return array_map(fn (array $entry): array => [
            ...$entry,
            'global_enabled' => array_key_exists($entry['key'], $globals) ? (bool) $globals[$entry['key']] : null,
        ], self::entries());
    }

    /**
     * Só as chaves do catálogo, como booleanos, a partir do JSON do plano.
     *
     * @return array<string, bool>
     */
    public static function booleans(?Plan $plan): array
    {
        $features = is_array($plan?->features) ? $plan->features : [];
        $result = [];

        foreach (self::keys() as $key) {
            $result[$key] = ($features[$key] ?? false) === true;
        }

        return $result;
    }
}
