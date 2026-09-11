<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use Illuminate\Support\Collection;

/**
 * Leitura dos documentos de um envelope na ordem de apresentação (Fase 2 §2.3).
 *
 * Um lugar só responde "quais são os documentos deste envelope, em que ordem, e qual versão
 * de cada um foi congelada no envio". Todo o pipeline (aceite, finalização, verificação)
 * passa por aqui — nunca por `$envelope->document`, que é só o PRIMEIRO.
 *
 * Sem escopo de organização: os chamadores (fluxo público, jobs) já resolveram o envelope
 * pela chave e pela organização; a consulta é SEMPRE restrita a `envelope_id` + a
 * `organization_id` do próprio envelope.
 */
final class EnvelopeDocuments
{
    /**
     * @return Collection<int, Document>
     */
    public static function ordered(Envelope $envelope): Collection
    {
        return Document::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->values();
    }

    public static function count(Envelope $envelope): int
    {
        return Document::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->count();
    }

    public static function isMulti(Envelope $envelope): bool
    {
        return self::count($envelope) > 1;
    }

    /**
     * Documento do envelope pelo ULID — `null` quando o ULID é de outro envelope ou de outra
     * organização (o chamador responde 404).
     */
    public static function find(Envelope $envelope, ?string $ulid): ?Document
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        /** @var Document|null */
        return Document::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->where('ulid', $ulid)
            ->first();
    }

    /**
     * Documentos com a versão congelada no envio, na ordem de apresentação.
     *
     * Para envelopes enviados antes da Fase 2 (`documents.sent_version_id` nulo), o primeiro
     * documento herda `envelopes.sent_document_version_id` — era ele o documento único.
     * Documento sem versão congelada não entra: não há o que apresentar nem o que aceitar.
     *
     * @return list<array{document: Document, version: DocumentVersion}>
     */
    public static function sent(Envelope $envelope): array
    {
        $documents = self::ordered($envelope);

        if ($documents->isEmpty()) {
            return [];
        }

        $first = $documents->first();
        $ids = [];

        foreach ($documents as $document) {
            $versionId = $document->sent_version_id
                ?? ($document->is($first) ? $envelope->sent_document_version_id : null);

            if ($versionId !== null) {
                $ids[$document->getKey()] = (int) $versionId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $versions = DocumentVersion::withoutOrganizationScope()
            ->whereIn('id', array_values($ids))
            ->where('organization_id', $envelope->organization_id)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($documents as $document) {
            $version = isset($ids[$document->getKey()]) ? $versions->get($ids[$document->getKey()]) : null;

            // A versão precisa ser DESTE documento: uma linha incoerente não vira apresentação.
            if ($version === null || (int) $version->document_id !== (int) $document->getKey()) {
                continue;
            }

            $rows[] = ['document' => $document, 'version' => $version];
        }

        return $rows;
    }

    /**
     * IDs das versões congeladas (para filtrar campos e valores).
     *
     * @return list<int>
     */
    public static function sentVersionIds(Envelope $envelope): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['version']->getKey(),
            self::sent($envelope),
        );
    }

    /**
     * Versões exibíveis correntes (preparo): `documents.current_version_id` de cada um.
     *
     * @return list<int>
     */
    public static function currentVersionIds(Envelope $envelope): array
    {
        return array_values(array_map(
            'intval',
            array_filter(self::ordered($envelope)->pluck('current_version_id')->all(), static fn ($id): bool => $id !== null),
        ));
    }

    /**
     * Próxima posição livre no envelope.
     */
    public static function nextPosition(Envelope $envelope): int
    {
        $max = Document::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->max('position');

        return ((int) $max) + 1;
    }

    /**
     * Regrava as posições 1..N na ordem atual (depois de remover um documento).
     */
    public static function compactPositions(Envelope $envelope): void
    {
        foreach (self::ordered($envelope) as $index => $document) {
            if ((int) $document->position !== $index + 1) {
                $document->forceFill(['position' => $index + 1])->save();
            }
        }
    }
}
