<?php

namespace App\Services\Ltv;

use App\Models\DocumentVersion;
use App\Models\VerificationRecord;
use App\Models\VerificationRecordDocument;
use App\Services\Ltv\Models\VerificationHashEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Histórico dos resumos finais de um registro de verificação (viabilidade §4.5 item 29).
 *
 * O re-carimbo de arquivamento acrescenta uma revisão ao PDF: o arquivo continua válido, mas o
 * SHA-256 muda. O resumo VIGENTE continua em `verification_records.final_sha256` (e em
 * `verification_record_documents`); esta tabela guarda o vigente e os anteriores, com as datas.
 *
 * Aditivo por construção: nenhuma linha existe antes do primeiro re-carimbo, e
 * {@see self::publicProps()} devolve `[]` nesse caso — a resposta pública atual não muda
 * enquanto houver um único resumo.
 *
 * Decisão de produto pendente: aceitar o resumo anterior na conferência pública. O ponto de
 * integração é {@see self::match()}, chamado por `PublicVerification::checkHash` (fora da área).
 */
final class VerificationHashHistory
{
    public const NOTICE = 'O arquivo final recebeu um novo carimbo do tempo de arquivamento, que acrescenta uma camada '
        .'ao PDF sem alterar o conteúdo do documento. Cada resumo abaixo identifica o arquivo tal como estava no '
        .'período indicado; um arquivo baixado antes da renovação continua sendo o mesmo documento.';

    public function recordReplacement(
        VerificationRecord $record,
        int $position,
        ?VerificationRecordDocument $row,
        DocumentVersion $previous,
        DocumentVersion $current,
        string $reason,
        LtvStatus $status,
        CarbonInterface $at,
    ): VerificationHashEntry {
        $recordId = (int) $record->getKey();
        $scope = VerificationHashEntry::query()->where('verification_record_id', $recordId)->where('position', $position);

        if (! (clone $scope)->exists()) {
            VerificationHashEntry::query()->create([
                'verification_record_id' => $recordId,
                'verification_record_document_id' => $row?->getKey(),
                'position' => $position,
                'document_version_id' => $previous->getKey(),
                'sha256' => $previous->sha256,
                'reason' => VerificationHashEntry::REASON_FINALIZED,
                'ltv_status' => null,
                'valid_from' => $record->validated_at ?? $record->created_at ?? $previous->created_at,
                'superseded_at' => $at,
                'created_at' => $at,
            ]);
        } else {
            (clone $scope)->whereNull('superseded_at')->update(['superseded_at' => $at]);
        }

        return VerificationHashEntry::query()->create([
            'verification_record_id' => $recordId,
            'verification_record_document_id' => $row?->getKey(),
            'position' => $position,
            'document_version_id' => $current->getKey(),
            'sha256' => $current->sha256,
            'reason' => $reason,
            'ltv_status' => $status->value,
            'valid_from' => $at,
            'superseded_at' => null,
            'created_at' => $at,
        ]);
    }

    /**
     * @return Collection<int, VerificationHashEntry>
     */
    public function entries(VerificationRecord $record): Collection
    {
        return VerificationHashEntry::query()
            ->where('verification_record_id', $record->getKey())
            ->orderBy('position')
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get();
    }

    /**
     * Chaves ADITIVAS para a verificação pública. Vazio enquanto houver um único resumo.
     *
     * @return array{}|array{hash_history: list<array{position: int, sha256: string, current: bool, valid_from: string|null, superseded_at: string|null, reason: string, reason_label: string}>, hash_history_notice: string}
     */
    public function publicProps(VerificationRecord $record): array
    {
        $entries = $this->entries($record);

        if ($entries->isEmpty()) {
            return [];
        }

        return [
            'hash_history' => array_values($entries->map(fn (VerificationHashEntry $entry): array => [
                'position' => $entry->position,
                'sha256' => $entry->sha256,
                'current' => $entry->isCurrent(),
                'valid_from' => $entry->valid_from?->toIso8601String(),
                'superseded_at' => $entry->superseded_at?->toIso8601String(),
                'reason' => $entry->reason,
                'reason_label' => self::reasonLabel($entry->reason),
            ])->all()),
            'hash_history_notice' => self::NOTICE,
        ];
    }

    /**
     * Um resumo ANTERIOR (substituído por re-carimbo) deste registro, ou null. O vigente continua
     * sendo conferido pelo caminho atual (`HashLedger`).
     *
     * @return array{matches: 'signed_previous', checked_sha256: string, position: int, valid_from: string|null, superseded_at: string|null}|null
     */
    public function match(VerificationRecord $record, string $sha256): ?array
    {
        $checked = strtolower(trim($sha256));

        if (preg_match('/^[a-f0-9]{64}$/', $checked) !== 1) {
            return null;
        }

        /** @var VerificationHashEntry|null $entry */
        $entry = VerificationHashEntry::query()
            ->where('verification_record_id', $record->getKey())
            ->where('sha256', $checked)
            ->whereNotNull('superseded_at')
            ->orderByDesc('valid_from')
            ->first();

        if ($entry === null) {
            return null;
        }

        return [
            'matches' => 'signed_previous',
            'checked_sha256' => $checked,
            'position' => $entry->position,
            'valid_from' => $entry->valid_from?->toIso8601String(),
            'superseded_at' => $entry->superseded_at?->toIso8601String(),
        ];
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            VerificationHashEntry::REASON_FINALIZED => 'Arquivo final na conclusão',
            VerificationHashEntry::REASON_LTV_REFRESH => 'Novo carimbo do tempo de arquivamento (o conteúdo do documento não mudou)',
            default => 'Arquivo final',
        };
    }
}
