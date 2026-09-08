<?php

use App\Integrations\Pdf\ImageToPdfConverter;
use App\Integrations\Pdf\LibreOfficeConverter;
use App\Integrations\Pdf\PassthroughPdfConverter;

$isWindows = PHP_OS_FAMILY === 'Windows';

return [

    /*
    |--------------------------------------------------------------------------
    | Interpretador Python do pdftool
    |--------------------------------------------------------------------------
    |
    | Caminho absoluto do Python do venv em tools/pdftool/.venv. O Laravel nunca
    | usa shell: o binário é executado diretamente com argumentos em array.
    | Padrão: .venv/Scripts/python.exe no Windows e .venv/bin/python no Linux.
    | (PDFTOOL_PYTHON vazio => padrão; venv ausente => isAvailable() = false.)
    |
    */

    'python' => env('PDFTOOL_PYTHON') ?: base_path(
        $isWindows ? 'tools/pdftool/.venv/Scripts/python.exe' : 'tools/pdftool/.venv/bin/python'
    ),

    /*
    | Diretório de trabalho do processo (o pacote `pdftool` precisa ser importável).
    */

    'cwd' => base_path('tools/pdftool'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (segundos)
    |--------------------------------------------------------------------------
    |
    | Cada comando roda com timeout próprio; ao estourar, o processo filho é
    | encerrado e uma PdfToolProcessingException (code=timeout) é lançada.
    | Assinatura tem timeout separado (PKCS#12 + pyHanko podem demorar mais).
    |
    */

    'timeout_seconds' => (int) env('PDFTOOL_TIMEOUT_SECONDS', 60),
    'sign_timeout_seconds' => (int) env('PDFTOOL_SIGN_TIMEOUT_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | Diretório temporário
    |--------------------------------------------------------------------------
    |
    | Raiz dos diretórios temporários exclusivos por operação
    | (<tmp_path>/<ulid>, permissão 0700, removidos em finally). Também é usado
    | como TEMP/TMP/TMPDIR/HOME do processo filho, para que nada seja gravado
    | fora de um lugar controlado.
    |
    */

    'tmp_path' => env('PDFTOOL_TMP_PATH') ?: storage_path('app/tmp/pdftool'),

    /*
    | Quantidade máxima de caracteres de stderr registrados em log por chamada.
    | O stderr nunca contém a senha do certificado (o pdftool não a imprime) e,
    | por precaução, o cliente ainda redige qualquer ocorrência do valor.
    */

    'stderr_log_limit' => 4000,

    /*
    |--------------------------------------------------------------------------
    | LibreOffice (DOCX → PDF)
    |--------------------------------------------------------------------------
    |
    | binary: caminho absoluto do soffice (Linux: /usr/bin/soffice;
    | Windows: C:\Program Files\LibreOffice\program\soffice.exe). Vazio =>
    | conversor não configurado (isConfigured() = false; convert() lança
    | ConverterNotConfiguredException). O processo roda com perfil de usuário
    | isolado (-env:UserInstallation em diretório temporário exclusivo) e
    | ambiente mínimo. Bloqueio de macros e de rede: ver docs/pdf-pipeline.md.
    |
    | env: variáveis adicionais para o processo do LibreOffice
    | (ex.: SAL_USE_VCLPLUGIN=svp em servidores sem X11). Nunca segredos.
    |
    */

    'libreoffice' => [
        'binary' => env('LIBREOFFICE_BIN'),
        'timeout_seconds' => (int) env('LIBREOFFICE_TIMEOUT_SECONDS', 120),
        'env' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificado A1 da empresa operadora (assinatura PAdES B-B)
    |--------------------------------------------------------------------------
    |
    | enabled: liga a assinatura criptográfica. Com false (padrão) o container
    | resolve PdfSigner para NullPdfSigner mesmo que haja PFX configurado, e os
    | envelopes concluem como aceite eletrônico com evidências.
    |
    | pfx_path: arquivo PKCS#12 (.pfx/.p12). password_env: NOME da variável de
    | ambiente que contém a senha do PKCS#12 (padrão COMPANY_CERT_PASSWORD). O
    | VALOR nunca entra na config, em argv, em log ou em fila: fica apenas no
    | ambiente do processo PHP e é injetado, sob esse mesmo nome, no ambiente do
    | processo filho do pdftool. Atenção: com `config:cache` o .env não é
    | carregado; nesse caso a variável precisa estar no ambiente real do serviço
    | (systemd EnvironmentFile, pool do PHP-FPM, etc.).
    |
    | PyHankoSigner::isConfigured() exige enabled=true + PFX existente + variável
    | de senha definida (não vazia) no ambiente do PHP + pdftool disponível.
    |
    | environment: test|production. Certificados de teste (gen-test-cert) são
    | sempre rotulados como teste e nunca exibidos como ICP-Brasil.
    |
    */

    'company_certificate' => [
        'enabled' => filter_var(env('COMPANY_CERT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'pfx_path' => env('COMPANY_CERT_PFX_PATH'),
        'password_env' => env('COMPANY_CERT_PASSWORD_ENV', 'COMPANY_CERT_PASSWORD'),
        'environment' => env('COMPANY_CERT_ENVIRONMENT', 'test'),
        'name' => env('COMPANY_CERT_NAME', 'Certificado da operadora'),
        'reason' => env('COMPANY_CERT_REASON', 'Assinatura eletrônica AssinaVelox'),
        'location' => env('COMPANY_CERT_LOCATION', 'Brasil'),
        'field_name' => 'AssinaVelox',
    ],

    /*
    |--------------------------------------------------------------------------
    | Raízes de confiança para `validate`
    |--------------------------------------------------------------------------
    |
    | Lista de arquivos PEM/DER separados por ";" (PDFTOOL_TRUST_ROOTS). Sem
    | raízes, validate() só afirma integridade (trusted=false,
    | trust_reason=no_trust_roots_configured). Revogação nunca é verificada.
    |
    */

    'trust_roots' => array_values(array_filter(array_map(
        'trim',
        explode(';', (string) env('PDFTOOL_TRUST_ROOTS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Conversores por tipo de origem (DocumentSourceType)
    |--------------------------------------------------------------------------
    |
    | Mapa fixo em config (não em env). Em desenvolvimento sem LibreOffice pode-se
    | apontar 'docx' para App\Integrations\Pdf\FakePdfConverter, que é
    | explicitamente identificado como fake (nome e log warning).
    |
    */

    'converters' => [
        'pdf' => PassthroughPdfConverter::class,
        'docx' => LibreOfficeConverter::class,
        'image' => ImageToPdfConverter::class,
    ],

    /*
    | FakePdfConverter: PDF copiado no lugar da conversão real (dev/test).
    */

    'fake' => [
        'fixture_path' => base_path('tests/Fixtures/fake-converted.pdf'),
    ],

];
