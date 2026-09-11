<?php

namespace App\Integrations\Contracts;

/**
 * Mensagens WhatsApp (código `whatsapp_otp`, aviso de convite) pelo serviço PRÓPRIO do
 * proprietário, com templates pré-aprovados por finalidade
 * (`assinavelox.channels.whatsapp.templates`).
 *
 * Nenhuma solução não oficial será adotada (Evolution API, WPPConnect, Baileys).
 * Implementações: App\Integrations\WhatsApp\FakeWhatsAppProvider (simulador identificado) e
 * App\Integrations\WhatsApp\HttpWhatsAppProvider (produção, desabilitado).
 */
interface WhatsAppProvider extends MessagingProvider {}
