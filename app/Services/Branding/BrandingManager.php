<?php

namespace App\Services\Branding;

use App\Models\Organization;
use App\Models\OrganizationBranding;
use App\Models\User;
use App\Services\Documents\DocumentStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Escrita da marca da organização (docs/fase-2/branding.md §3).
 *
 * Toda consulta aqui é **explicitamente** por `organization_id` e sem o escopo global —
 * o serviço também roda fora do middleware `org` (fila de e-mail, página pública,
 * finalização). Nunca se busca uma marca por outro critério além da organização dona
 * (ou do token opaco do logo, em {@see self::findByLogoToken()}).
 *
 * O logo fica no disco PRIVADO `documents`, em um caminho montado só com identificadores
 * opacos. O arquivo antigo é apagado DEPOIS que a nova linha é gravada: se a gravação
 * falhar, a marca anterior continua íntegra.
 */
class BrandingManager
{
    public function __construct(private readonly LogoProcessor $processor) {}

    public function find(?Organization $organization): ?OrganizationBranding
    {
        if ($organization === null) {
            return null;
        }

        return OrganizationBranding::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->first();
    }

    public function findByLogoToken(string $token): ?OrganizationBranding
    {
        if (preg_match('/^[a-f0-9]{40}$/', $token) !== 1) {
            return null;
        }

        return OrganizationBranding::withoutOrganizationScope()
            ->with('organization')
            ->where('logo_token', $token)
            ->first();
    }

    /**
     * Nome de exibição, cores, Reply-To e remetente desejado. Só as chaves presentes em
     * `$data` mudam; string vazia volta ao padrão (null).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $organization, ?User $user, array $data): OrganizationBranding
    {
        $branding = $this->find($organization) ?? $this->newFor($organization);

        foreach (['display_name', 'reply_to_email', 'sender_email'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_string($data[$key]) ? trim($data[$key]) : null;
                $value = $value === '' ? null : $value;

                if ($value !== null && $key !== 'display_name') {
                    $value = mb_strtolower($value);
                }

                if ($value !== null && $key === 'display_name') {
                    $value = self::cleanName($value);
                }

                $branding->setAttribute($key, $value);
            }
        }

        foreach (['primary_color', 'accent_color'] as $key) {
            if (array_key_exists($key, $data)) {
                $branding->setAttribute($key, ColorContrast::normalize(is_string($data[$key]) ? $data[$key] : null));
            }
        }

        $branding->updated_by_user_id = $user?->getKey();
        $branding->save();

        return $branding;
    }

    /**
     * Processa (GD) e troca o logo. O token opaco muda a cada envio: a URL antiga passa a
     * responder o marcador transparente, e nenhum cache guarda o logo anterior.
     */
    public function replaceLogo(Organization $organization, ?User $user, string $sourcePath): OrganizationBranding
    {
        $logo = $this->processor->process($sourcePath);
        $token = bin2hex(random_bytes(20));
        $path = $this->logoPathFor($organization, $token);

        $this->disk()->put($path, $logo['bytes']);

        $previous = null;

        try {
            $branding = DB::transaction(function () use ($organization, $user, $logo, $token, $path, &$previous): OrganizationBranding {
                $branding = $this->find($organization) ?? $this->newFor($organization);
                $previous = $branding->logo_path;

                $branding->forceFill([
                    'logo_path' => $path,
                    'logo_token' => $token,
                    'logo_width' => $logo['width'],
                    'logo_height' => $logo['height'],
                    'logo_bytes' => strlen($logo['bytes']),
                    'logo_sha256' => $logo['sha256'],
                    'updated_by_user_id' => $user?->getKey(),
                ])->save();

                return $branding;
            });
        } catch (\Throwable $exception) {
            $this->disk()->delete($path);

            throw $exception;
        }

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            $this->disk()->delete($previous);
        }

        return $branding;
    }

    public function removeLogo(Organization $organization, ?User $user): ?OrganizationBranding
    {
        $branding = $this->find($organization);

        if ($branding === null || ! $branding->hasLogo()) {
            return $branding;
        }

        $path = (string) $branding->logo_path;

        $branding->forceFill([
            'logo_path' => null,
            'logo_token' => null,
            'logo_width' => null,
            'logo_height' => null,
            'logo_bytes' => null,
            'logo_sha256' => null,
            'updated_by_user_id' => $user?->getKey(),
        ])->save();

        $this->disk()->delete($path);

        return $branding;
    }

    /**
     * Bytes do PNG normalizado, ou null quando não há logo (ou o arquivo sumiu).
     */
    public function logoBytes(?OrganizationBranding $branding): ?string
    {
        if ($branding === null || ! $branding->hasLogo()) {
            return null;
        }

        $disk = $this->disk();
        $path = (string) $branding->logo_path;

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * Exclusão da organização (contrato para `OrganizationPurge`, fora desta área): apaga
     * o arquivo do logo. A linha sai pelo `cascadeOnDelete` da FK.
     */
    public function purge(Organization $organization): void
    {
        $branding = $this->find($organization);

        if ($branding?->logo_path !== null && $branding->logo_path !== '') {
            $this->disk()->delete($branding->logo_path);
        }

        $this->disk()->deleteDirectory($this->directoryFor($organization));
    }

    public function logoPathFor(Organization $organization, string $token): string
    {
        return $this->directoryFor($organization).'/logo-'.$token.'.png';
    }

    private function directoryFor(Organization $organization): string
    {
        $prefix = trim((string) config('assinavelox.upload.path_prefix', 'orgs'), '/');

        return $prefix.'/'.$organization->ulid.'/branding';
    }

    private function newFor(Organization $organization): OrganizationBranding
    {
        $branding = new OrganizationBranding;
        $branding->organization_id = (int) $organization->getKey();

        return $branding;
    }

    public function disk(): Filesystem
    {
        return Storage::disk(DocumentStorage::DISK);
    }

    /** Colapsa espaços e remove caracteres de controle/formatação invisível. */
    public static function cleanName(string $name): string
    {
        $clean = preg_replace('/[\p{C}]+/u', '', $name) ?? '';
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';

        return mb_substr(trim($clean), 0, BrandingLimits::MAX_DISPLAY_NAME);
    }
}
