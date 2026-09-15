<?php

declare(strict_types=1);

use AssinaVelox\Sdk\ApiResult;
use AssinaVelox\Sdk\DownloadedFile;
use AssinaVelox\Sdk\FileUpload;
use AssinaVelox\Sdk\Model;
use AssinaVelox\Sdk\Page;

/*
 * Cada operação da especificação contra o servidor falso. Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */

SdkTest::add('operação listEnvelopes (v1.envelopes.index)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->listEnvelopes([], $options);
    $t->instanceOf(Page::class, $result);
    $t->same(2, count($result->data));
    $t->same('fake-cursor-2', $result->nextCursor());
    $t->instanceOf(Model\Envelope::class, $result->data[0]);
    $t->true($result->data[0]->createdAt !== null, 'campo created_at lido');
    $t->true($result->data[0]->displayCode !== null, 'campo display_code lido');
    $t->true($result->data[0]->id !== null, 'campo id lido');
    $t->true($result->data[0]->object !== null, 'campo object lido');
    $t->true($result->data[0]->recipientsCount !== null, 'campo recipients_count lido');
    $t->true($result->data[0]->signedCount !== null, 'campo signed_count lido');
    $t->true($result->data[0]->signingOrder !== null, 'campo signing_order lido');
    $t->true($result->data[0]->status !== null, 'campo status lido');
    $t->true($result->data[0]->statusLabel !== null, 'campo status_label lido');
    $t->true($result->data[0]->title !== null, 'campo title lido');
    $t->true($result->data[0]->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->data[0]->viewersCount !== null, 'campo viewers_count lido');
    $t->true($result->data[0]->raw !== []);
    $following = $result->nextPage();
    $t->true($following !== null);
    $t->same(1, count($following->data));
    $t->false($following->hasMore());
    $t->same(null, $following->nextPage());
    $t->same(3, iterator_count($result->autoPagingIterator()));
    $t->checkTrace($trace, 'v1.envelopes.index', null);
});

SdkTest::add('operação createEnvelope (v1.envelopes.store)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->createEnvelope(json_decode('{"title": "exemplo de title"}', true, 512, JSON_THROW_ON_ERROR), $options);
    $t->instanceOf(Model\Envelope::class, $result);
    $t->true($result->createdAt !== null, 'campo created_at lido');
    $t->true($result->displayCode !== null, 'campo display_code lido');
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->recipientsCount !== null, 'campo recipients_count lido');
    $t->true($result->signedCount !== null, 'campo signed_count lido');
    $t->true($result->signingOrder !== null, 'campo signing_order lido');
    $t->true($result->status !== null, 'campo status lido');
    $t->true($result->statusLabel !== null, 'campo status_label lido');
    $t->true($result->title !== null, 'campo title lido');
    $t->true($result->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->viewersCount !== null, 'campo viewers_count lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.store', 'required');
});

SdkTest::add('operação getEnvelope (v1.envelopes.show)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->getEnvelope('01J00000000000000000000000', $options);
    $t->instanceOf(Model\Envelope::class, $result);
    $t->true($result->createdAt !== null, 'campo created_at lido');
    $t->true($result->displayCode !== null, 'campo display_code lido');
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->recipientsCount !== null, 'campo recipients_count lido');
    $t->true($result->signedCount !== null, 'campo signed_count lido');
    $t->true($result->signingOrder !== null, 'campo signing_order lido');
    $t->true($result->status !== null, 'campo status lido');
    $t->true($result->statusLabel !== null, 'campo status_label lido');
    $t->true($result->title !== null, 'campo title lido');
    $t->true($result->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->viewersCount !== null, 'campo viewers_count lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.show', null);
});

SdkTest::add('operação uploadDocument (v1.envelopes.documents.store)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->uploadDocument('01J00000000000000000000000', FileUpload::fromString("\x25\x50\x44\x46\x2d\x31\x2e\x34\x0d\x0a\x25\x20\x74\x65\x73\x74\x65\x20\x64\x6f\x20\x53\x44\x4b\x00\xff\x0d\x0a\x25\x25\x45\x4f\x46\x0a", 'contrato.pdf', 'application/pdf'), $options);
    $t->instanceOf(Model\Document::class, $result);
    $t->true($result->createdAt !== null, 'campo created_at lido');
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->name !== null, 'campo name lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->originalFilename !== null, 'campo original_filename lido');
    $t->true($result->position !== null, 'campo position lido');
    $t->true($result->processingLabel !== null, 'campo processing_label lido');
    $t->true($result->processingStatus !== null, 'campo processing_status lido');
    $t->true($result->ready !== null, 'campo ready lido');
    $t->true($result->sourceType !== null, 'campo source_type lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.documents.store', 'required');
});

SdkTest::add('operação listRecipients (v1.envelopes.recipients.index)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->listRecipients('01J00000000000000000000000', $options);
    $t->true(is_array($result) && count($result) >= 1);
    $t->instanceOf(Model\Recipient::class, $result[0]);
    $t->true($result[0]->id !== null, 'campo id lido');
    $t->true($result[0]->notificationsCount !== null, 'campo notifications_count lido');
    $t->true($result[0]->object !== null, 'campo object lido');
    $t->true($result[0]->order !== null, 'campo order lido');
    $t->true($result[0]->role !== null, 'campo role lido');
    $t->true($result[0]->roleLabel !== null, 'campo role_label lido');
    $t->true($result[0]->status !== null, 'campo status lido');
    $t->true($result[0]->statusLabel !== null, 'campo status_label lido');
    $t->true($result[0]->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.recipients.index', null);
});

SdkTest::add('operação syncRecipients (v1.envelopes.recipients.sync)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->syncRecipients('01J00000000000000000000000', json_decode('{"recipients": [{"email": "ana@example.com", "name": "exemplo de name"}], "signing_order": "sequential"}', true, 512, JSON_THROW_ON_ERROR), $options);
    $t->true(is_array($result) && count($result) >= 1);
    $t->instanceOf(Model\Recipient::class, $result[0]);
    $t->true($result[0]->id !== null, 'campo id lido');
    $t->true($result[0]->notificationsCount !== null, 'campo notifications_count lido');
    $t->true($result[0]->object !== null, 'campo object lido');
    $t->true($result[0]->order !== null, 'campo order lido');
    $t->true($result[0]->role !== null, 'campo role lido');
    $t->true($result[0]->roleLabel !== null, 'campo role_label lido');
    $t->true($result[0]->status !== null, 'campo status lido');
    $t->true($result[0]->statusLabel !== null, 'campo status_label lido');
    $t->true($result[0]->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.recipients.sync', 'optional');
});

SdkTest::add('operação listFields (v1.envelopes.fields.index)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->listFields('01J00000000000000000000000', $options);
    $t->true(is_array($result) && count($result) >= 1);
    $t->instanceOf(Model\Field::class, $result[0]);
    $t->true($result[0]->auto !== null, 'campo auto lido');
    $t->true($result[0]->h !== null, 'campo h lido');
    $t->true($result[0]->id !== null, 'campo id lido');
    $t->true($result[0]->object !== null, 'campo object lido');
    $t->true($result[0]->page !== null, 'campo page lido');
    $t->true($result[0]->required !== null, 'campo required lido');
    $t->true($result[0]->type !== null, 'campo type lido');
    $t->true($result[0]->w !== null, 'campo w lido');
    $t->true($result[0]->x !== null, 'campo x lido');
    $t->true($result[0]->y !== null, 'campo y lido');
    $t->true($result[0]->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.fields.index', null);
});

SdkTest::add('operação syncFields (v1.envelopes.fields.sync)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->syncFields('01J00000000000000000000000', json_decode('{"fields": [{"h": 0.5, "page": 1, "type": "signature", "w": 0.5, "x": 0.5, "y": 0.5}]}', true, 512, JSON_THROW_ON_ERROR), $options);
    $t->true(is_array($result) && count($result) >= 1);
    $t->instanceOf(Model\Field::class, $result[0]);
    $t->true($result[0]->auto !== null, 'campo auto lido');
    $t->true($result[0]->h !== null, 'campo h lido');
    $t->true($result[0]->id !== null, 'campo id lido');
    $t->true($result[0]->object !== null, 'campo object lido');
    $t->true($result[0]->page !== null, 'campo page lido');
    $t->true($result[0]->required !== null, 'campo required lido');
    $t->true($result[0]->type !== null, 'campo type lido');
    $t->true($result[0]->w !== null, 'campo w lido');
    $t->true($result[0]->x !== null, 'campo x lido');
    $t->true($result[0]->y !== null, 'campo y lido');
    $t->true($result[0]->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.fields.sync', 'optional');
});

SdkTest::add('operação sendEnvelope (v1.envelopes.send)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->sendEnvelope('01J00000000000000000000000', $options);
    $t->instanceOf(ApiResult::class, $result);
    $t->true(is_array($result->meta));
    $t->instanceOf(Model\Envelope::class, $result->data);
    $t->true($result->data->createdAt !== null, 'campo created_at lido');
    $t->true($result->data->displayCode !== null, 'campo display_code lido');
    $t->true($result->data->id !== null, 'campo id lido');
    $t->true($result->data->object !== null, 'campo object lido');
    $t->true($result->data->recipientsCount !== null, 'campo recipients_count lido');
    $t->true($result->data->signedCount !== null, 'campo signed_count lido');
    $t->true($result->data->signingOrder !== null, 'campo signing_order lido');
    $t->true($result->data->status !== null, 'campo status lido');
    $t->true($result->data->statusLabel !== null, 'campo status_label lido');
    $t->true($result->data->title !== null, 'campo title lido');
    $t->true($result->data->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->data->viewersCount !== null, 'campo viewers_count lido');
    $t->true($result->data->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.send', 'required');
});

SdkTest::add('operação cancelEnvelope (v1.envelopes.cancel)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->cancelEnvelope('01J00000000000000000000000', json_decode('{}', true, 512, JSON_THROW_ON_ERROR), $options);
    $t->instanceOf(ApiResult::class, $result);
    $t->true(is_array($result->meta));
    $t->instanceOf(Model\Envelope::class, $result->data);
    $t->true($result->data->createdAt !== null, 'campo created_at lido');
    $t->true($result->data->displayCode !== null, 'campo display_code lido');
    $t->true($result->data->id !== null, 'campo id lido');
    $t->true($result->data->object !== null, 'campo object lido');
    $t->true($result->data->recipientsCount !== null, 'campo recipients_count lido');
    $t->true($result->data->signedCount !== null, 'campo signed_count lido');
    $t->true($result->data->signingOrder !== null, 'campo signing_order lido');
    $t->true($result->data->status !== null, 'campo status lido');
    $t->true($result->data->statusLabel !== null, 'campo status_label lido');
    $t->true($result->data->title !== null, 'campo title lido');
    $t->true($result->data->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->data->viewersCount !== null, 'campo viewers_count lido');
    $t->true($result->data->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.cancel', 'optional');
});

SdkTest::add('operação downloadFile (v1.envelopes.files.show)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->downloadFile('01J00000000000000000000000', 'original', [], $options);
    $t->instanceOf(DownloadedFile::class, $result);
    $t->true(str_starts_with($result->content, '%PDF'));
    $t->same('application/pdf', $result->contentType);
    $t->same('AV-000123-original.pdf', $result->filename);
    $t->checkTrace($trace, 'v1.envelopes.files.show', null);
});

SdkTest::add('operação listEvents (v1.envelopes.events.index)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->listEvents('01J00000000000000000000000', [], $options);
    $t->instanceOf(Page::class, $result);
    $t->same(2, count($result->data));
    $t->same('fake-cursor-2', $result->nextCursor());
    $t->instanceOf(Model\Event::class, $result->data[0]);
    $t->true($result->data[0]->id !== null, 'campo id lido');
    $t->true($result->data[0]->kind !== null, 'campo kind lido');
    $t->true($result->data[0]->label !== null, 'campo label lido');
    $t->true($result->data[0]->object !== null, 'campo object lido');
    $t->true($result->data[0]->occurredAt !== null, 'campo occurred_at lido');
    $t->true($result->data[0]->type !== null, 'campo type lido');
    $t->true($result->data[0]->raw !== []);
    $following = $result->nextPage();
    $t->true($following !== null);
    $t->same(1, count($following->data));
    $t->false($following->hasMore());
    $t->same(null, $following->nextPage());
    $t->same(3, iterator_count($result->autoPagingIterator()));
    $t->checkTrace($trace, 'v1.envelopes.events.index', null);
});

SdkTest::add('operação getVerification (v1.envelopes.verification.show)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->getVerification('01J00000000000000000000000', $options);
    $t->true(is_array($result));
    $t->checkTrace($trace, 'v1.envelopes.verification.show', null);
});

SdkTest::add('operação listTemplates (v1.templates.index)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->listTemplates([], $options);
    $t->instanceOf(Page::class, $result);
    $t->same(2, count($result->data));
    $t->same('fake-cursor-2', $result->nextCursor());
    $t->instanceOf(Model\Template::class, $result->data[0]);
    $t->true($result->data[0]->id !== null, 'campo id lido');
    $t->true($result->data[0]->name !== null, 'campo name lido');
    $t->true($result->data[0]->object !== null, 'campo object lido');
    $t->true($result->data[0]->sourceType !== null, 'campo source_type lido');
    $t->true($result->data[0]->status !== null, 'campo status lido');
    $t->true($result->data[0]->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->data[0]->usable !== null, 'campo usable lido');
    $t->true($result->data[0]->raw !== []);
    $following = $result->nextPage();
    $t->true($following !== null);
    $t->same(1, count($following->data));
    $t->false($following->hasMore());
    $t->same(null, $following->nextPage());
    $t->same(3, iterator_count($result->autoPagingIterator()));
    $t->checkTrace($trace, 'v1.templates.index', null);
});

SdkTest::add('operação getTemplate (v1.templates.show)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->getTemplate('01J00000000000000000000000', $options);
    $t->instanceOf(Model\Template::class, $result);
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->name !== null, 'campo name lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->sourceType !== null, 'campo source_type lido');
    $t->true($result->status !== null, 'campo status lido');
    $t->true($result->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->usable !== null, 'campo usable lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.templates.show', null);
});

SdkTest::add('operação createEnvelopeFromTemplate (v1.templates.envelopes.store)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->createEnvelopeFromTemplate('01J00000000000000000000000', json_decode('{}', true, 512, JSON_THROW_ON_ERROR), $options);
    $t->instanceOf(Model\Envelope::class, $result);
    $t->true($result->createdAt !== null, 'campo created_at lido');
    $t->true($result->displayCode !== null, 'campo display_code lido');
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->recipientsCount !== null, 'campo recipients_count lido');
    $t->true($result->signedCount !== null, 'campo signed_count lido');
    $t->true($result->signingOrder !== null, 'campo signing_order lido');
    $t->true($result->status !== null, 'campo status lido');
    $t->true($result->statusLabel !== null, 'campo status_label lido');
    $t->true($result->title !== null, 'campo title lido');
    $t->true($result->updatedAt !== null, 'campo updated_at lido');
    $t->true($result->viewersCount !== null, 'campo viewers_count lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.templates.envelopes.store', 'required');
});

SdkTest::add('operação listWebhookEvents (v1.webhook_events.index)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->listWebhookEvents($options);
    $t->true(is_array($result) && count($result) >= 1);
    $t->checkTrace($trace, 'v1.webhook_events.index', null);
});

SdkTest::add('operação getWebhookEventSample (v1.webhook_events.sample)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->getWebhookEventSample('envelope.sent', $options);
    $t->instanceOf(ApiResult::class, $result);
    $t->true(is_array($result->meta));
    $t->true(is_array($result->data) && count($result->data) >= 1);
    $t->checkTrace($trace, 'v1.webhook_events.sample', null);
});

SdkTest::add('operação listWebhookSubscriptions (v1.webhook_subscriptions.index)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->listWebhookSubscriptions($options);
    $t->instanceOf(ApiResult::class, $result);
    $t->true(is_array($result->meta));
    $t->true(is_array($result->data) && count($result->data) >= 1);
    $t->instanceOf(Model\WebhookSubscription::class, $result->data[0]);
    $t->true($result->data[0]->id !== null, 'campo id lido');
    $t->true($result->data[0]->object !== null, 'campo object lido');
    $t->true($result->data[0]->targetUrl !== null, 'campo target_url lido');
    $t->true($result->data[0]->status !== null, 'campo status lido');
    $t->true($result->data[0]->statusLabel !== null, 'campo status_label lido');
    $t->true($result->data[0]->secretHint !== null, 'campo secret_hint lido');
    $t->true($result->data[0]->signatureHeader !== null, 'campo signature_header lido');
    $t->true($result->data[0]->createdAt !== null, 'campo created_at lido');
    $t->true($result->data[0]->raw !== []);
    $t->checkTrace($trace, 'v1.webhook_subscriptions.index', null);
});

SdkTest::add('operação createWebhookSubscription (v1.webhook_subscriptions.store)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->createWebhookSubscription(json_decode('{"target_url": "https://integracao.example/webhooks/assinavelox"}', true, 512, JSON_THROW_ON_ERROR), $options);
    $t->instanceOf(ApiResult::class, $result);
    $t->true(is_array($result->meta));
    $t->instanceOf(Model\WebhookSubscription::class, $result->data);
    $t->true($result->data->id !== null, 'campo id lido');
    $t->true($result->data->object !== null, 'campo object lido');
    $t->true($result->data->targetUrl !== null, 'campo target_url lido');
    $t->true($result->data->status !== null, 'campo status lido');
    $t->true($result->data->statusLabel !== null, 'campo status_label lido');
    $t->true($result->data->secretHint !== null, 'campo secret_hint lido');
    $t->true($result->data->signatureHeader !== null, 'campo signature_header lido');
    $t->true($result->data->createdAt !== null, 'campo created_at lido');
    $t->true($result->data->raw !== []);
    $t->checkTrace($trace, 'v1.webhook_subscriptions.store', 'required');
});

SdkTest::add('operação deleteWebhookSubscription (v1.webhook_subscriptions.destroy)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $t->client()->deleteWebhookSubscription('01J00000000000000000000000', $options);
    $t->checkTrace($trace, 'v1.webhook_subscriptions.destroy', null);
});

SdkTest::add('operação createEmbeddedSession (v1.envelopes.recipients.embedded_sessions.store)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->createEmbeddedSession('01J00000000000000000000000', '01J00000000000000000000000', json_decode('{"origin": "exemplo de origin"}', true, 512, JSON_THROW_ON_ERROR), $options);
    $t->instanceOf(Model\EmbeddedSigningSession::class, $result);
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->origin !== null, 'campo origin lido');
    $t->true($result->status !== null, 'campo status lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.recipients.embedded_sessions.store', 'required');
});

SdkTest::add('operação getEmbeddedSession (v1.envelopes.recipients.embedded_sessions.show)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->getEmbeddedSession('01J00000000000000000000000', '01J00000000000000000000000', '01J00000000000000000000000', $options);
    $t->instanceOf(Model\EmbeddedSigningSession::class, $result);
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->origin !== null, 'campo origin lido');
    $t->true($result->status !== null, 'campo status lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.recipients.embedded_sessions.show', null);
});

SdkTest::add('operação revokeEmbeddedSession (v1.envelopes.recipients.embedded_sessions.destroy)', static function (TestContext $t): void {
    [$options, $trace] = $t->trace();
    $result = $t->client()->revokeEmbeddedSession('01J00000000000000000000000', '01J00000000000000000000000', '01J00000000000000000000000', $options);
    $t->instanceOf(Model\EmbeddedSigningSession::class, $result);
    $t->true($result->id !== null, 'campo id lido');
    $t->true($result->object !== null, 'campo object lido');
    $t->true($result->origin !== null, 'campo origin lido');
    $t->true($result->status !== null, 'campo status lido');
    $t->true($result->raw !== []);
    $t->checkTrace($trace, 'v1.envelopes.recipients.embedded_sessions.destroy', null);
});
