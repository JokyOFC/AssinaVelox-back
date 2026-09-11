<?php

namespace App\Services\Identity;

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Signing\SignerAudit;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Retenção das imagens da captura (docs/fase-2/identidade.md §5.5).
 *
 * - Foto vinculada a aceite: o ARQUIVO sai `retention_days` depois da captura; a linha fica
 *   com `storage_path = null` e `purged_at`, porque o aceite referencia o resumo SHA-256 e a
 *   evidência precisa dizer que a imagem existiu e foi excluída. `retention_days = 0` desliga.
 * - Foto que nunca virou aceite (sessão abandonada, envelope encerrado): sai inteira depois
 *   de `orphan_retention_hours`.
 * - {@see self::forEnvelope()}: tudo do envelope, para a exclusão do envelope.
 *
 * Cada envelope afetado ganha um `identity_capture.purged` com a contagem (sem imagem).
 * A exclusão da ORGANIZAÇÃO já leva os arquivos (diretório `orgs/{ulid}`) e as linhas (cascata
 * de `recipients`/`envelopes`).
 *
 * Integração pendente (fora da área C-ID): comando Artisan + agendamento diário chamando
 * {@see self::run()} — ver o relatório.
 */
final class CapturePurge
{
    /**
     * @return array{expired: int, orphans: int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $expired = 0;
        $orphans = 0;
        $perEnvelope = [];

        $retentionDays = (int) config('assinavelox.capture.retention_days', 180);

        if ($retentionDays > 0) {
            IdentityCapture::withoutOrganizationScope()
                ->whereNotNull('signature_acceptance_id')
                ->whereNotNull('storage_path')
                ->where('captured_at', '<', $now->copy()->subDays($retentionDays))
                ->orderBy('id')
                ->chunkById(200, function ($captures) use ($now, &$expired, &$perEnvelope): void {
                    foreach ($captures as $capture) {
                        $this->deleteFile($capture);
                        $capture->forceFill(['storage_path' => null, 'purged_at' => $now])->save();
                        $expired++;
                        $perEnvelope[$capture->envelope_id]['retention'] = ($perEnvelope[$capture->envelope_id]['retention'] ?? 0) + 1;
                    }
                });
        }

        $orphanHours = max(1, (int) config('assinavelox.capture.orphan_retention_hours', 48));

        IdentityCapture::withoutOrganizationScope()
            ->whereNull('signature_acceptance_id')
            ->where('captured_at', '<', $now->copy()->subHours($orphanHours))
            ->orderBy('id')
            ->chunkById(200, function ($captures) use (&$orphans, &$perEnvelope): void {
                foreach ($captures as $capture) {
                    $this->deleteFile($capture);
                    $capture->delete();
                    $orphans++;
                    $perEnvelope[$capture->envelope_id]['orphan'] = ($perEnvelope[$capture->envelope_id]['orphan'] ?? 0) + 1;
                }
            });

        $this->audit($perEnvelope);

        return ['expired' => $expired, 'orphans' => $orphans];
    }

    public function forEnvelope(Envelope $envelope, ?CarbonInterface $now = null): int
    {
        $now ??= Carbon::now();
        $count = 0;

        IdentityCapture::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->whereNotNull('storage_path')
            ->orderBy('id')
            ->chunkById(200, function ($captures) use ($now, &$count): void {
                foreach ($captures as $capture) {
                    $this->deleteFile($capture);
                    $capture->forceFill(['storage_path' => null, 'purged_at' => $now])->save();
                    $count++;
                }
            });

        if ($count > 0) {
            SignerAudit::system($envelope, AuditEventType::IdentityCapturePurged, ['count' => $count, 'reason' => 'envelope_deleted']);
        }

        return $count;
    }

    private function deleteFile(IdentityCapture $capture): void
    {
        if (is_string($capture->storage_path) && $capture->storage_path !== '') {
            Storage::disk('documents')->delete($capture->storage_path);
        }
    }

    /**
     * @param  array<int, array<string, int>>  $perEnvelope
     */
    private function audit(array $perEnvelope): void
    {
        if ($perEnvelope === []) {
            return;
        }

        $envelopes = Envelope::withoutOrganizationScope()->whereIn('id', array_keys($perEnvelope))->get()->keyBy('id');

        foreach ($perEnvelope as $envelopeId => $counts) {
            $envelope = $envelopes->get($envelopeId);

            if (! $envelope instanceof Envelope) {
                continue;
            }

            foreach ($counts as $reason => $count) {
                SignerAudit::system($envelope, AuditEventType::IdentityCapturePurged, ['count' => $count, 'reason' => $reason]);
            }
        }
    }
}
