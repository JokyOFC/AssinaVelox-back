# tests/Fixtures

Artefatos estáticos usados pelos testes de `tests/Feature/Pdf`. Tudo o que pode ser gerado em tempo de teste **é** gerado (PDFs com `barryvdh/laravel-dompdf`, PNG/JPEG com GD, certificados com `pdftool gen-test-cert`); aqui ficam apenas os itens que precisam existir antes do teste rodar.

| Arquivo | Uso |
|---|---|
| `fake-converted.pdf` | PDF de 1 página (A4) copiado pelo `App\Integrations\Pdf\FakePdfConverter` e pelo LibreOffice falso. Texto visível "DOCUMENTO FAKE". Gerado por `generate-fake-pdf.py` (reportlab do venv do pdftool, `invariant=True`, sem compressão, sem metadados variáveis). |
| `generate-fake-pdf.py` | Script one-off que regenera `fake-converted.pdf`. Rodar a partir de `tools/pdftool/`: `.venv/Scripts/python.exe ../../tests/Fixtures/generate-fake-pdf.py` (Windows) ou `.venv/bin/python ../../tests/Fixtures/generate-fake-pdf.py` (Linux). |
| `fake-soffice.bat` / `fake-soffice.sh` | Binário **falso** do LibreOffice para exercitar `LibreOfficeConverter` sem o LibreOffice instalado. Grava em `FAKE_SOFFICE_LOG` a linha `ARGS=<argumentos recebidos>`, `PROFILE_XCU=1|0` (se `<outdir>/../profile/user/registrymodifications.xcu` existe), `CWD=` e o ambiente completo (`set`/`env`) para o teste verificar que apenas o ambiente mínimo foi repassado; copia `FAKE_SOFFICE_PDF` para `<outdir>/<entrada>.pdf`. `FAKE_SOFFICE_FAIL` força exit 1; `FAKE_SOFFICE_NOOUT` força exit 0 sem saída. O teste escolhe `.bat` no Windows e `.sh` nos demais (aplicando `chmod +x`). |

## PDFs cifrados

Não há fixture cifrado versionado: `Tests\Feature\Pdf\Support\PdfFixtures::encryptedPdf()` gera PDFs cifrados em tempo de teste com `Barryvdh\DomPDF\PDF::setEncryption($senhaUsuario, $senhaProprietario)` (RC4 via CPDF), tanto só com senha de proprietário (o pdftool abre com senha vazia e responde `encrypted: true`) quanto com senha de usuário (o pdftool recusa com `encrypted_pdf`, exit 4).

## Ao versionar

`fake-soffice.sh` precisa do bit de execução no Linux: `git update-index --chmod=+x tests/Fixtures/fake-soffice.sh` (o teste também aplica `chmod` em tempo de execução).
