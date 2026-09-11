<?php

namespace App\Services\Reports;

use App\Enums\EnvelopeStatus;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Filtros do relatório de documentos (validados; nada vindo do navegador é usado sem passar
 * por aqui). Pasta, time e etiqueta são resolvidos por ULID DENTRO da organização da
 * membership — um ULID de outra organização vira "filtro inexistente" (nenhum resultado),
 * nunca um vazamento.
 *
 * Período em dias do fuso da organização, `from` e `to` inclusivos, no máximo 366 dias.
 */
final class ReportFilters
{
    public const MAX_DAYS = 366;

    public const DEFAULT_DAYS = 30;

    /** Status atuais aceitos no filtro. */
    public const STATUSES = ['in_progress', 'completed', 'refused', 'expired', 'canceled'];

    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $timezone,
        public readonly ?EnvelopeStatus $status,
        public readonly ?Folder $folder,
        public readonly ?Team $team,
        public readonly ?int $creatorId,
        public readonly ?Tag $tag,
        /** Algum filtro referenciou um registro inexistente nesta organização. */
        public readonly bool $unresolved,
    ) {}

    public static function fromRequest(Request $request, Membership $membership, string $timezone): self
    {
        $validator = validator($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'folder' => ['nullable', 'string', 'size:26'],
            'team' => ['nullable', 'string', 'size:26'],
            'creator' => ['nullable', 'integer', 'min:1'],
            'tag' => ['nullable', 'string', 'size:26'],
        ], [], [
            'from' => 'início do período',
            'to' => 'fim do período',
            'folder' => 'pasta',
            'team' => 'time',
            'creator' => 'criado por',
            'tag' => 'etiqueta',
        ]);

        $validated = $validator->validate();

        $today = Carbon::now($timezone)->startOfDay();
        $to = isset($validated['to']) ? Carbon::createFromFormat('Y-m-d', $validated['to'], $timezone)->startOfDay() : $today->copy();
        $from = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', $validated['from'], $timezone)->startOfDay()
            : $to->copy()->subDays(self::DEFAULT_DAYS - 1);

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to' => 'O fim do período deve ser igual ou posterior ao início.']);
        }

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'O período pode ter no máximo '.self::MAX_DAYS.' dias.']);
        }

        $organizationId = $membership->organization_id;
        $folder = null;
        if (! empty($validated['folder'])) {
            $folder = Folder::forOrganization($organizationId)->where('ulid', $validated['folder'])->first();
        }

        $team = null;
        if (! empty($validated['team'])) {
            $team = Team::forOrganization($organizationId)->where('ulid', $validated['team'])->first();
        }

        $tag = null;
        if (! empty($validated['tag'])) {
            $tag = Tag::forOrganization($organizationId)->where('ulid', $validated['tag'])->first();
        }

        // Algum ULID informado não existe nesta organização → o relatório fica vazio.
        $unresolved = (! empty($validated['folder']) && $folder === null)
            || (! empty($validated['team']) && $team === null)
            || (! empty($validated['tag']) && $tag === null);

        return new self(
            from: $from,
            to: $to,
            timezone: $timezone,
            status: isset($validated['status']) ? EnvelopeStatus::from($validated['status']) : null,
            folder: $folder,
            team: $team,
            creatorId: isset($validated['creator']) ? (int) $validated['creator'] : null,
            tag: $tag,
            unresolved: $unresolved,
        );
    }

    /** Início do período em UTC (para comparar com as colunas). */
    public function startUtc(): Carbon
    {
        return $this->from->copy()->startOfDay()->utc();
    }

    /** Fim do período (fim do dia `to`) em UTC. */
    public function endUtc(): Carbon
    {
        return $this->to->copy()->endOfDay()->utc();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Forma serializada para a página (e para a trilha da exportação — só ULIDs).
     *
     * @return array{from: string, to: string, status: string|null, folder: string|null, team: string|null, creator: string|null, tag: string|null}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'status' => $this->status?->value,
            'folder' => $this->folder?->ulid,
            'team' => $this->team?->ulid,
            'creator' => $this->creatorId !== null ? (string) $this->creatorId : null,
            'tag' => $this->tag?->ulid,
        ];
    }
}
