<?php

/*
|--------------------------------------------------------------------------
| Textos jurídicos de la página pública — español (Fase 3 §3.3, F-I18N)
|--------------------------------------------------------------------------
|
| TRADUCCIÓN DE CORTESÍA. La versión de referencia es la de portugués de
| Brasil (lang/pt_BR/signer_legal.php, idéntica a
| App\Services\Signing\ConsentText), que es el texto registrado como evidencia.
| Esta traducción TODAVÍA NO fue revisada por un profesional jurídico
| (docs/fase-3/multilingue.md §7). Mismas claves y mismos marcadores que el
| archivo de referencia.
|
*/

return [
    'heading' => [
        'sign' => 'Declaración de aceptación electrónica',
        'witness' => 'Declaración de aceptación electrónica como testigo',
        'approve' => 'Declaración de aprobación electrónica',
    ],
    'version_line' => ':heading — versión :version',
    'intro' => 'Yo, :name, identificado(a) en esta solicitud por el correo electrónico :email,:capacity declaro que:',
    'capacity' => [
        'sign' => '',
        'witness' => ' en calidad de testigo,',
        'approve' => ' en calidad de aprobador(a),',
    ],
    'item1' => [
        'sign' => [
            'single' => '1. He leído íntegramente el documento ":title", enviado por :sender, cuyo contenido presentado en esta pantalla corresponde al resumen SHA-256 :sha, y estoy de acuerdo con su contenido.',
            'multi' => '1. He leído íntegramente los :count documentos de la solicitud ":title", enviados por :sender, cuyos contenidos presentados en esta pantalla corresponden a los resúmenes SHA-256 indicados abajo, y estoy de acuerdo con el contenido de cada uno de ellos.',
        ],
        'witness' => [
            'single' => '1. Tuve acceso íntegro al documento ":title", enviado por :sender, cuyo contenido presentado en esta pantalla corresponde al resumen SHA-256 :sha, y declaro, en calidad de testigo, haber tomado conocimiento de su contenido y de su celebración por medio de esta plataforma. Esta declaración no me convierte en parte del documento ni expresa mi conformidad personal con las obligaciones previstas en él.',
            'multi' => '1. Tuve acceso íntegro a los :count documentos de la solicitud ":title", enviados por :sender, cuyos contenidos presentados en esta pantalla corresponden a los resúmenes SHA-256 indicados abajo, y declaro, en calidad de testigo, haber tomado conocimiento de su contenido y de su celebración por medio de esta plataforma. Esta declaración no me convierte en parte de los documentos ni expresa mi conformidad personal con las obligaciones previstas en ellos.',
        ],
        'approve' => [
            'single' => '1. He leído íntegramente el documento ":title", enviado por :sender, cuyo contenido presentado en esta pantalla corresponde al resumen SHA-256 :sha, y apruebo el contenido del documento.',
            'multi' => '1. He leído íntegramente los :count documentos de la solicitud ":title", enviados por :sender, cuyos contenidos presentados en esta pantalla corresponden a los resúmenes SHA-256 indicados abajo, y apruebo el contenido de cada uno de ellos.',
        ],
    ],
    'document_line' => '   1.:index. ":document" — SHA-256 :sha',
    'item2' => [
        'sign' => '2. Los campos que completé en esta pantalla y la imagen de firma que proporcioné (dibujada, escrita o enviada) son de mi autoría. Reconozco que esa imagen es una representación visual y que mi manifestación de voluntad es esta aceptación.',
        'witness' => '2. Los campos que completé en esta pantalla y la imagen de firma que proporcioné (dibujada, escrita o enviada) son de mi autoría. Reconozco que esa imagen es una representación visual y que mi manifestación es esta aceptación electrónica como testigo.',
        'approve' => '2. Esta aprobación no contiene representación visual de firma y no me convierte en firmante del documento. Los campos que eventualmente completé en esta pantalla son de mi autoría.',
    ],
    'item3' => '3. Soy consciente de que AssinaVelox registrará, como evidencia de :subject: la fecha y la hora del servidor (UTC), mi dirección IP, la identificación de mi navegador, el método de autenticación (:method):simulated, la versión exacta de :version_of, los campos presentados y los valores completados:extras, y esta declaración.',
    'subject' => [
        'acceptance' => 'esta aceptación',
        'approval' => 'esta aprobación',
    ],
    'version_of' => [
        'single' => 'el documento',
        'multi' => 'cada documento',
    ],
    'method' => [
        'email' => 'código de un solo uso confirmado en el correo electrónico indicado arriba',
        'sms' => 'código de un solo uso enviado por SMS al móvil informado por quien envió el documento',
        'whatsapp' => 'código de un solo uso enviado por WhatsApp al móvil informado por quien envió el documento',
        'pin_suffix' => ', seguido del PIN acordado con quien envió el documento',
    ],
    'simulated_note' => ' [envío por :channel simulado en esta instalación: no se transmitió ningún mensaje al móvil]',
    'extras' => [
        'cpf_lookup' => ', el resultado de la consulta registral del CPF que informé, realizada a un servicio utilizado por AssinaVelox (la consulta no confirma que yo sea el titular del CPF)',
        'photos' => ', las fotos que envié en esta pantalla (:photos), guardadas como registro, sin verificación de identidad',
        // Fase 4 §4.1: con la verificación facial con documento exigida, las fotos van al proveedor nombrado.
        'photos_verification' => ', las fotos que envié en esta pantalla (:photos), enviadas al proveedor externo :provider, que compara la foto tomada en el momento con la foto del documento y devuelve un resultado — el resultado informado por el proveedor queda guardado como evidencia de esta aceptación y las fotos siguen el plazo de conservación indicado en el aviso de privacidad —',
    ],
    'item4' => [
        'certificate' => [
            'single' => '4. Soy consciente de que, al final de la recolección de todas las aceptaciones, AssinaVelox (:operator) consolidará el documento con una página de evidencias y aplicará al archivo final una firma criptográfica con un certificado digital de su propia titularidad. Esa firma identifica a AssinaVelox como operadora de la plataforma y permite detectar cambios posteriores en el archivo; no es mi firma personal ni un certificado digital emitido a mi nombre.',
            'multi' => '4. Soy consciente de que, al final de la recolección de todas las aceptaciones, AssinaVelox (:operator) consolidará cada documento con una página de evidencias y aplicará a cada archivo final una firma criptográfica con un certificado digital de su propia titularidad. Esa firma identifica a AssinaVelox como operadora de la plataforma y permite detectar cambios posteriores en el archivo; no es mi firma personal ni un certificado digital emitido a mi nombre.',
        ],
        'no_certificate' => [
            'single' => '4. Soy consciente de que, al final de la recolección de todas las aceptaciones, AssinaVelox (:operator) consolidará el documento con una página de evidencias y lo concluirá como aceptación electrónica con evidencias, sin firma criptográfica. La integridad del archivo final podrá comprobarse mediante su resumen SHA-256, publicado en la página de verificación :url con el código :code.',
            'multi' => '4. Soy consciente de que, al final de la recolección de todas las aceptaciones, AssinaVelox (:operator) consolidará cada documento con una página de evidencias y los concluirá como aceptación electrónica con evidencias, sin firma criptográfica. La integridad de cada archivo final podrá comprobarse mediante el respectivo resumen SHA-256, publicado en la página de verificación :url con el código :code.',
        ],
    ],
    'item5' => [
        'sign' => '5. Soy consciente de que la validez y los efectos de esta aceptación dependen de la legislación aplicable y del acuerdo entre las partes, y de que AssinaVelox no garantiza su aceptación por terceros.',
        'witness' => '5. Soy consciente de que la validez y los efectos de esta declaración de testigo dependen de la legislación aplicable y del acuerdo entre las partes, y de que AssinaVelox no garantiza su aceptación por terceros.',
        'approve' => '5. Soy consciente de que la validez y los efectos de esta aprobación dependen de la legislación aplicable y del acuerdo entre las partes, y de que AssinaVelox no garantiza su aceptación por terceros.',
    ],
    'item6' => [
        'sign' => '6. He leído el aviso de privacidad mostrado en esta página y sé que puedo rechazar la firma indicando un motivo.',
        'witness' => '6. He leído el aviso de privacidad mostrado en esta página y sé que puedo rechazar la firma como testigo indicando un motivo.',
        'approve' => '6. He leído el aviso de privacidad mostrado en esta página y sé que puedo rechazar la aprobación indicando un motivo.',
    ],

    'label' => [
        'sign' => [
            'single' => 'He leído el documento :title y declaro que estoy de acuerdo con su contenido y que los datos aquí registrados — fecha y hora, dirección IP, navegador, :code, la versión exacta del documento y los campos que completé — constituyen evidencia de mi aceptación electrónica.',
            'multi' => 'He leído los :count documentos de :title y declaro que estoy de acuerdo con el contenido de cada uno y que los datos aquí registrados — fecha y hora, dirección IP, navegador, :code, la versión exacta de cada documento y los campos que completé — constituyen evidencia de mi aceptación electrónica.',
        ],
        'witness' => [
            'single' => 'Tuve acceso al documento :title y declaro, en calidad de testigo, que los datos aquí registrados — fecha y hora, dirección IP, navegador, :code, la versión exacta del documento y los campos que completé — constituyen evidencia de mi aceptación electrónica como testigo.',
            'multi' => 'Tuve acceso a los :count documentos de :title y declaro, en calidad de testigo, que los datos aquí registrados — fecha y hora, dirección IP, navegador, :code, la versión exacta de cada documento y los campos que completé — constituyen evidencia de mi aceptación electrónica como testigo.',
        ],
        'approve' => [
            'single' => 'He leído el documento :title y declaro que apruebo su contenido y que los datos aquí registrados — fecha y hora, dirección IP, navegador, :code y la versión exacta del documento — constituyen evidencia de mi aprobación electrónica.',
            'multi' => 'He leído los :count documentos de :title y declaro que apruebo su contenido y que los datos aquí registrados — fecha y hora, dirección IP, navegador, :code y la versión exacta de cada documento — constituyen evidencia de mi aprobación electrónica.',
        ],
    ],
    'code' => [
        'email' => 'código confirmado por correo electrónico',
        'sms' => 'código confirmado por SMS',
        'whatsapp' => 'código confirmado por WhatsApp',
        'pin_suffix' => ' y PIN acordado con quien envió el documento',
    ],

    'privacy_summary' => 'Este documento fue enviado por :organization. :purpose, AssinaVelox registrará la fecha, la IP, el navegador, la versión exacta del documento y el :code.',
    'privacy_purpose' => [
        'signer' => 'Para registrar su aceptación',
        'approver' => 'Para registrar su aprobación',
        'viewer' => 'Para registrar su visualización',
    ],
    'privacy_notice' => [
        'signer' => [
            'Cómo se usan sus datos en esta página',
            'Quién es responsable de sus datos. Este documento fue enviado por :organization, que decidió solicitar su firma y es la responsable del tratamiento de sus datos personales. AssinaVelox (:operator) es la encargada: trata los datos solo para ejecutar la firma, siguiendo las instrucciones de quien envió el documento.',
            'Qué registramos y por qué. Para que su aceptación tenga valor como evidencia, registramos: :registered; la fecha y la hora del servidor (UTC); su dirección IP y la identificación del navegador; la versión exacta del documento que usted vio (resumen SHA-256); los campos mostrados y los valores que usted complete; la imagen de su firma (dibujada, escrita o enviada); y el texto de aceptación que usted marque. Estos datos forman la página de evidencias adjunta al documento final, entregada a quien lo envió y a usted.',
            'La apertura de este enlace queda registrada. Al abrir esta página, registramos la fecha, la IP y el navegador como "apertura detectada". Esto no significa que usted haya leído o aceptado algo.',
            'Si usted no quiere firmar. Puede simplemente cerrar esta página, o usar "Rechazar firma" e indicar el motivo, que se enviará a quien envió el documento. No solicitar el código no genera ninguna aceptación.',
            'Lo que no hacemos. No pedimos :not_asked. No usamos cookies de seguimiento ni anuncios en esta página; solo cookies esenciales de sesión y seguridad.',
            'Por cuánto tiempo. Las evidencias se guardan mientras el documento exista en la cuenta de quien lo envió o mientras haya una obligación legal o la necesidad de comprobar la aceptación.',
            'Sus derechos. Para acceder, corregir o pedir información sobre sus datos, contacte primero a quien envió el documento. También puede escribir a AssinaVelox a :support.',
        ],
        'approver' => [
            'Cómo se usan sus datos en esta página',
            'Quién es responsable de sus datos. Este documento fue enviado por :organization, que decidió solicitar su aprobación y es la responsable del tratamiento de sus datos personales. AssinaVelox (:operator) es la encargada: trata los datos solo para registrar la aprobación, siguiendo las instrucciones de quien envió el documento.',
            'Qué registramos y por qué. Para que su aprobación tenga valor como evidencia, registramos: :registered; la fecha y la hora del servidor (UTC); su dirección IP y la identificación del navegador; la versión exacta del documento que usted vio (resumen SHA-256); los campos mostrados y los valores que usted complete; y el texto de aprobación que usted marque. La aprobación no usa imagen de firma. Estos datos forman la página de evidencias adjunta al documento final, entregada a quien lo envió y a usted.',
            'La apertura de este enlace queda registrada. Al abrir esta página, registramos la fecha, la IP y el navegador como "apertura detectada". Esto no significa que usted haya leído o aceptado algo.',
            'Si usted no quiere aprobar. Puede simplemente cerrar esta página, o rechazar e indicar el motivo, que se enviará a quien envió el documento. No solicitar el código no genera ninguna aprobación.',
            'Lo que no hacemos. No pedimos :not_asked. No usamos cookies de seguimiento ni anuncios en esta página; solo cookies esenciales de sesión y seguridad.',
            'Por cuánto tiempo. Las evidencias se guardan mientras el documento exista en la cuenta de quien lo envió o mientras haya una obligación legal o la necesidad de comprobar la aprobación.',
            'Sus derechos. Para acceder, corregir o pedir información sobre sus datos, contacte primero a quien envió el documento. También puede escribir a AssinaVelox a :support.',
        ],
        'viewer' => [
            'Cómo se usan sus datos en esta página',
            'Quién es responsable de sus datos. Este documento fue enviado por :organization, que decidió compartirlo con usted para seguimiento y es la responsable del tratamiento de sus datos personales. AssinaVelox (:operator) es la encargada: trata los datos solo para poner el documento a su disposición, siguiendo las instrucciones de quien envió el documento.',
            'Qué registramos y por qué. Para comprobar el acceso al documento, registramos: :registered; la fecha y la hora del servidor (UTC); su dirección IP y la identificación del navegador; y la versión exacta del documento que usted vio (resumen SHA-256). Como observador, usted no registra aceptación, no completa campos y no se guarda ninguna imagen de firma.',
            'La apertura de este enlace queda registrada. Al abrir esta página, registramos la fecha, la IP y el navegador como "apertura detectada". Esto no significa que usted haya leído o aceptado algo.',
            'Usted no necesita hacer nada. Puede simplemente cerrar esta página. No solicitar el código no genera ningún registro además de la apertura.',
            'Lo que no hacemos. No pedimos :not_asked. No usamos cookies de seguimiento ni anuncios en esta página; solo cookies esenciales de sesión y seguridad.',
            'Por cuánto tiempo. Los registros se guardan mientras el documento exista en la cuenta de quien lo envió o mientras haya una obligación legal o la necesidad de comprobar el acceso.',
            'Sus derechos. Para acceder, corregir o pedir información sobre sus datos, contacte primero a quien envió el documento. También puede escribir a AssinaVelox a :support.',
        ],
    ],
    'registered' => [
        'base' => ':who (informados por quien envió el documento); el código de confirmación enviado :where (guardado solo de forma irreversible)',
        'who' => [
            'email' => 'su nombre y correo electrónico',
            'phone' => 'su nombre, correo electrónico y móvil',
        ],
        'where' => [
            'email' => 'a su correo electrónico',
            'sms' => 'por SMS a su móvil',
            'whatsapp' => 'por WhatsApp a su móvil',
        ],
        'pin' => '; la confirmación del PIN que quien envió el documento acordó con usted (el PIN también se guarda solo de forma irreversible)',
        'cpf_lookup' => '; el CPF que usted escriba en el documento, que no se comprueba solo por los dígitos: también se envía a un servicio de consulta registral utilizado por AssinaVelox, solo para comprobar la situación del número en la base de ese servicio, y el resultado de la consulta queda registrado con la aceptación (la consulta no confirma que usted sea el titular del CPF)',
        'cpf' => '; el CPF que usted escriba en el documento, comprobado solo por los dígitos (esto no confirma la titularidad)',
        'photos' => '; las fotos que usted envíe (:photos), guardadas como registro de la aceptación, sin comparación de rostros, sin análisis de la imagen y sin lectura del documento, y :retention',
        // Fase 4 §4.1: el envío al proveedor nombrado y la finalidad (LGPD art. 9 y 11).
        'photos_verification' => '; las fotos que usted envíe (:photos), enviadas al proveedor externo :provider solo para comparar la foto tomada en el momento con la foto del documento (la plataforma no compara las imágenes: envía las fotos y registra la respuesta del proveedor, que queda guardada como evidencia de la aceptación), y :retention',
        'retention_days' => 'eliminadas :days días después',
        'retention_kept' => 'guardadas mientras el documento exista en la cuenta de quien lo envió',
    ],
    'not_asked' => [
        'password' => 'contraseña',
        'cpf' => 'CPF',
        'photo' => 'foto',
        'location' => 'ubicación',
        'or' => ' ni ',
    ],
    'photo_kinds' => [
        'selfie' => 'foto del rostro',
        'document_front' => 'foto del documento (anverso)',
        'document_back' => 'foto del documento (reverso)',
    ],

    'completion' => [
        'certificate' => 'Al final, AssinaVelox aplicará al archivo una firma criptográfica con un certificado de su propia titularidad. Identifica a la operadora y detecta cambios posteriores; no es su firma personal.',
        'no_certificate' => 'Al final, este documento se concluirá como aceptación electrónica con evidencias, sin firma criptográfica. La integridad del archivo se comprueba mediante el resumen SHA-256 publicado en la página de verificación.',
        'participant_certificate' => 'Al final, AssinaVelox aplicará al archivo una firma criptográfica con un certificado de su propia titularidad (identifica a la operadora, no es su firma personal). ',
        'participant_no_certificate' => 'Al final, este documento se concluirá como aceptación electrónica con evidencias. ',
        'participant_suffix' => 'Si usted envía su propio certificado digital dentro del plazo, el archivo también recibirá una firma criptográfica hecha con su propio certificado, que se suma a su aceptación. La integridad del archivo se comprueba mediante el resumen SHA-256 publicado en la página de verificación.',
    ],
];
