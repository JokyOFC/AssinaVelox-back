@echo off
rem ---------------------------------------------------------------------------
rem FAKE do LibreOffice para os testes de tests/Feature/Pdf (Windows).
rem NAO e o LibreOffice: apenas registra os argumentos recebidos e copia um PDF
rem fixture para <--outdir>\<nome-da-entrada>.pdf, como "soffice --convert-to pdf"
rem faria. Controlado por variaveis de ambiente passadas pelo teste via
rem config('pdftool.libreoffice.env'):
rem   FAKE_SOFFICE_LOG   arquivo onde gravar argumentos + ambiente recebido
rem   FAKE_SOFFICE_PDF   PDF a copiar como resultado
rem   FAKE_SOFFICE_FAIL  se definido, termina com exit code 1 sem gerar saida
rem   FAKE_SOFFICE_NOOUT se definido, termina com exit code 0 sem gerar saida
rem Assume o layout de diretorios do LibreOfficeConverter: <tmp>\out e <tmp>\profile.
rem ---------------------------------------------------------------------------
setlocal
set "OUTDIR="
set "INPUT="
set "PREV="

if defined FAKE_SOFFICE_LOG (
  > "%FAKE_SOFFICE_LOG%" echo ARGS=%*
)

:loop
if "%~1"=="" goto done
if "%PREV%"=="--outdir" set "OUTDIR=%~1"
set "PREV=%~1"
set "INPUT=%~1"
shift
goto loop

:done
if not defined OUTDIR (
  echo fake-soffice: --outdir ausente 1>&2
  exit /b 2
)

for %%F in ("%INPUT%") do set "BASE=%%~nF"

if defined FAKE_SOFFICE_LOG (
  if exist "%OUTDIR%\..\profile\user\registrymodifications.xcu" (
    >> "%FAKE_SOFFICE_LOG%" echo PROFILE_XCU=1
  ) else (
    >> "%FAKE_SOFFICE_LOG%" echo PROFILE_XCU=0
  )
  >> "%FAKE_SOFFICE_LOG%" echo CWD=%CD%
  >> "%FAKE_SOFFICE_LOG%" echo --- ENV ---
  set >> "%FAKE_SOFFICE_LOG%"
)

if defined FAKE_SOFFICE_FAIL exit /b 1
if defined FAKE_SOFFICE_NOOUT exit /b 0

rem copy interpreta "/" como inicio de opcao: normaliza para barras invertidas.
set "SRC=%FAKE_SOFFICE_PDF:/=\%"
set "OUTDIR=%OUTDIR:/=\%"
copy /Y "%SRC%" "%OUTDIR%\%BASE%.pdf" >nul
if errorlevel 1 exit /b 1
exit /b 0
