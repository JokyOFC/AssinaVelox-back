<?php

namespace App\Services\Organizations;

use App\Enums\SubscriptionStatus;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\BrandingManager;
use App\Support\OrganizationSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;

/**
 * Exclusão efetiva da organização depois da carência (ROUTES_AND_PAGES §2.12 e Q23).
 *
 * A tela de Configurações › Geral e segurança promete "Remove todos os usuários e
 * documentos após 30 dias" e responde "Exclusão agendada para dd/mm/aaaa"; a política de
 * privacidade publicada promete ao titular que "os dados são apagados dos sistemas
 * ativos". Até esta correção, `settings.deletion_requested_at` era só uma data guardada:
 * nenhum comando, job ou agendamento a lia, e passados os 30 dias nada acontecia.
 *
 * ## O que é apagado
 *
 * Tudo o que pertence à organização, banco e disco: envelopes, documentos e todas as
 * versões (inclusive a final e o relatório de evidências), campos, destinatários,
 * convites, sessões, desafios, aceites, imagens de assinatura, tentativas de entrega,
 * registros de verificação pública, pastas, membros, convites de equipe, referências de
 * certificado, consumo do plano, pagamentos e a assinatura — e a própria organização, em
 * `forceDelete` (o soft delete não elimina nada).
 *
 * Os arquivos saem por dois caminhos, de propósito: o diretório inteiro `orgs/{ulid}` do
 * disco privado `documents` — onde vivem os PDFs e os PNGs de assinatura, porque
 * DocumentStorage e SignatureImages montam o caminho a partir do ULID da organização — e,
 * por garantia, cada `document_versions.storage_path` e cada
 * `signature_acceptances.signature_image_path` registrados, colhidos ANTES do commit.
 *
 * ## Usuários
 *
 * Só é apagado o usuário que fica sem NENHUMA organização depois desta e que não é autor de
 * envelope algum que tenha sobrado (`envelopes.created_by_user_id` é RESTRICT — "autor é
 * parte da evidência", docs/banco-de-dados.md §4.2) nem administrador da plataforma. Quem
 * participa de outra organização continua com a conta; era isso que "remove todos os
 * usuários" queria dizer — os usuários DAQUELA organização.
 *
 * ## A trilha de auditoria — decisão explícita
 *
 * `audit_events` da organização é ELIMINADO junto. A trilha é evidência sobre os documentos
 * daquela organização; mantê-la depois de apagar os documentos guardaria dados pessoais dos
 * signatários (nome, e-mail, IP) sem o objeto que os justificava, contra a promessa feita ao
 * titular. O que permanece é o recibo de exclusão abaixo — sem dado pessoal — e os
 * `audit_checkpoints`, que são resumos encadeados da plataforma inteira e cuja retenção é
 * política operacional (docs/seguranca-operacional.md §3), não dado da organização.
 *
 * ## Recibo
 *
 * Sai no log estruturado (`organization.purged`) com o ULID, o momento do pedido, o momento
 * da execução e as contagens do que foi removido. Não vai para `audit_events`: essa tabela
 * acabou de ser esvaziada para esta organização, e um evento sobrevivente reintroduziria a
 * chave estrangeira que estamos removendo.
 */
class OrganizationPurge
{
    /**
     * Ordem de remoção: filhos antes dos pais.
     *
     * As FKs para `organizations` são quase todas RESTRICT de propósito (docs/banco-de-dados
     * §4.2: apagar uma organização não pode ser um acidente de cascata), então a ordem aqui
     * é a própria regra de integridade — cada tabela sai depois de tudo o que aponta para
     * ela.
     *
     * @var list<string>
     */
    private const TABLES_IN_ORDER = [
        // Fase 2: "acessar como" é RESTRICT na organização (a sessão de suporte é trilha);
        // sai antes de tudo. `platform_audit_events` fica — é trilha da plataforma
        // (organization_id nullOnDelete).
        'impersonations',
        // Fase 2, onda B (antes dos envelopes, destinatários, aceites e modelos que referenciam).
        // `identity_captures` e `identity_capture_requirements` são RESTRICT na organização.
        'identity_captures',
        'identity_capture_requirements',
        'recipient_pins',
        'in_person_turns',
        'in_person_sessions',
        'batch_signing_items',
        'batch_signing_challenges',
        'batch_signing_sessions',
        'public_form_submissions',
        'public_forms',
        'sender_domains',
        'organization_brandings',
        'signing_field_values',
        'acceptance_documents',
        'signature_acceptances',
        'signing_fields',
        'auth_challenges',
        'signing_session_documents',
        'signing_sessions',
        'recipient_access_links',
        'delivery_attempts',
        'envelope_reminders',
        'recipients',
        'verification_records',
        'audit_events',
        'document_versions',
        'documents',
        // Fase 2: etiquetas e modelos (filhos antes dos pais).
        'envelope_tag',
        'tags',
        'template_usages',
        'template_fields',
        'template_roles',
        'template_variables',
        'template_versions',
        'templates',
        'plan_consumptions',
        'payments',
        'subscriptions',
        'envelopes',
        'certificate_references',
        'membership_invitations',
        // Fase 2: acesso por pasta e times antes de pastas/membros; funções depois dos membros.
        'folder_permissions',
        'teams',
        'folders',
        'memberships',
        'roles',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Organizações cuja carência já venceu.
     *
     * @return Collection<int, Organization>
     */
    public function due(?Carbon $now = null, ?int $limit = null): Collection
    {
        $now ??= Carbon::now();

        $query = Organization::query()->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit * 20);
        }

        return $query->get()
            ->filter(function (Organization $organization) use ($now): bool {
                $scheduled = OrganizationSettings::of($organization)->deletionScheduledFor();

                return $scheduled !== null && $scheduled->lessThanOrEqualTo($now);
            })
            ->when($limit !== null, fn ($collection) => $collection->take($limit))
            ->values();
    }

    /**
     * Apaga a organização e tudo o que é dela. Idempotente: uma organização já removida
     * simplesmente não aparece em `due()`.
     *
     * @return array{organization: string, rows: array<string, int>, users_deleted: int, files_removed: bool}
     */
    public function purge(Organization $organization): array
    {
        $ulid = $organization->ulid;
        $requestedAt = OrganizationSettings::of($organization)->deletionRequestedAt();
        $organizationId = (int) $organization->getKey();

        // Caminhos de arquivo COLETADOS ANTES do commit: depois de apagar as linhas não há
        // mais como saber o que existia em disco. O diretório `orgs/{ulid}` cobre o layout
        // de produção (DocumentStorage e SignatureImages); a lista cobre qualquer versão
        // gravada com outro caminho.
        $files = $this->filePaths($organizationId);

        /** @var list<int> $candidateUserIds */
        $candidateUserIds = Membership::query()
            ->where('organization_id', $organizationId)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        $rows = DB::transaction(function () use ($organizationId, $organization): array {
            // As colunas que apontam para `document_versions` são nullOnDelete, mas o
            // envelope ainda será lido pelas contagens: solta as referências antes.
            DB::table('envelopes')
                ->where('organization_id', $organizationId)
                ->update(['sent_document_version_id' => null, 'final_document_version_id' => null]);

            DB::table('documents')
                ->where('organization_id', $organizationId)
                ->update(['current_version_id' => null]);

            // A assinatura é encerrada antes de sair: se algum recibo do provedor chegar
            // atrasado, ele encontra uma assinatura cancelada e não reativa nada.
            DB::table('subscriptions')
                ->where('organization_id', $organizationId)
                ->update([
                    'status' => SubscriptionStatus::Canceled->value,
                    'canceled_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            $rows = [];

            foreach (self::TABLES_IN_ORDER as $table) {
                $rows[$table] = DB::table($table)->where('organization_id', $organizationId)->delete();
            }

            // `users.current_organization_id` é nullOnDelete, mas zerar antes evita que um
            // usuário que sobrevive continue apontando para o que já não existe.
            DB::table('users')
                ->where('current_organization_id', $organizationId)
                ->update(['current_organization_id' => null]);

            $organization->forceDelete();

            return $rows;
        });

        $usersDeleted = $this->deleteOrphanedUsers($candidateUserIds);

        // Os bytes saem DEPOIS do commit: uma transação que falha não deixa arquivo
        // apagado sem registro; um arquivo que resiste vira aviso em log, nunca um
        // registro fantasma no banco.
        $filesRemoved = $this->removeFiles($ulid, $files);

        // Fase 2 §2.8 (C-BRAND): logo da marca (diretório próprio do BrandingManager).
        try {
            app(BrandingManager::class)->purge($organization);
        } catch (\Throwable $exception) {
            $filesRemoved = false;

            $this->logger->warning('organization.purge.branding_not_removed', [
                'organization' => $ulid,
                'exception' => $exception->getMessage(),
            ]);
        }

        $receipt = [
            'organization' => $ulid,
            'rows' => $rows,
            'users_deleted' => $usersDeleted,
            'files_removed' => $filesRemoved,
        ];

        $this->logger->info('organization.purged', $receipt + [
            'requested_at' => $requestedAt?->toIso8601String(),
            'purged_at' => Carbon::now()->toIso8601String(),
        ]);

        return $receipt;
    }

    /**
     * Usuários que ficaram sem organização nenhuma. Administradores da plataforma e autores
     * de envelopes que sobraram continuam — a FK de autoria é RESTRICT justamente para que
     * a evidência não perca o autor.
     *
     * @param  list<int>  $userIds
     */
    private function deleteOrphanedUsers(array $userIds): int
    {
        $deleted = 0;

        foreach ($userIds as $userId) {
            /** @var User|null $user */
            $user = User::query()->find($userId);

            if ($user === null || $user->is_platform_admin) {
                continue;
            }

            $stillMember = Membership::query()->where('user_id', $userId)->exists();

            $stillAuthor = Envelope::withoutOrganizationScope()
                ->withTrashed()
                ->where('created_by_user_id', $userId)
                ->exists();

            if ($stillMember || $stillAuthor) {
                continue;
            }

            DB::table('notifications')
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $userId)
                ->delete();

            $user->forceDelete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Todo arquivo desta organização no disco privado `documents`: as versões dos documentos
     * (original, exibível, consolidado, evidências, final) e as imagens de assinatura.
     *
     * @return list<string>
     */
    private function filePaths(int $organizationId): array
    {
        $versions = DB::table('document_versions')
            ->where('organization_id', $organizationId)
            ->where('storage_disk', 'documents')
            ->pluck('storage_path')
            ->all();

        $images = DB::table('signature_acceptances')
            ->where('organization_id', $organizationId)
            ->whereNotNull('signature_image_path')
            ->pluck('signature_image_path')
            ->all();

        return array_values(array_unique(array_map(
            static fn ($path): string => (string) $path,
            array_merge($versions, $images),
        )));
    }

    /**
     * Apaga os bytes: o diretório `orgs/{ulid}` (layout de produção — DocumentStorage e
     * SignatureImages montam o caminho a partir do ULID da organização) e, por garantia,
     * cada caminho registrado nas linhas que acabaram de sair.
     *
     * @param  list<string>  $files
     */
    private function removeFiles(string $organizationUlid, array $files): bool
    {
        $prefix = trim((string) $this->config->get('assinavelox.upload.path_prefix', 'orgs'), '/');
        $directory = $prefix.'/'.$organizationUlid;

        $disk = Storage::disk('documents');
        $removed = true;

        try {
            $disk->deleteDirectory($directory);
        } catch (\Throwable $exception) {
            $removed = false;

            $this->logger->warning('organization.purge.files_not_removed', [
                'organization' => $organizationUlid,
                'directory' => $directory,
                'exception' => $exception->getMessage(),
            ]);
        }

        foreach ($files as $path) {
            if ($path === '') {
                continue;
            }

            try {
                $disk->delete($path);
            } catch (\Throwable $exception) {
                $removed = false;

                $this->logger->warning('organization.purge.file_not_removed', [
                    'organization' => $organizationUlid,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $removed;
    }
}
