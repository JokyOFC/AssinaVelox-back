import type { PickedDropboxFile } from './types';

/*
 * Carregadores do Google Picker e do Dropbox Chooser (Fase 3 §3.9, G-CONN).
 *
 * Os scripts dos provedores só são liberados pela CSP da página de importação
 * (App\Services\CloudImport\CloudImportCsp); em qualquer outra página o navegador os
 * bloqueia. Nada aqui guarda token: o do Google vem de um POST JSON na hora de abrir o
 * Picker e morre com ele.
 */

const loaded = new Map<string, Promise<void>>();

function loadScript(
    src: string,
    attributes: Record<string, string> = {},
): Promise<void> {
    const cached = loaded.get(src);

    if (cached) {
        return cached;
    }

    const promise = new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;

        for (const [name, value] of Object.entries(attributes)) {
            script.setAttribute(name, value);
        }

        script.onload = () => resolve();
        script.onerror = () => {
            loaded.delete(src);
            script.remove();
            reject(new Error('script_load_failed'));
        };

        document.head.appendChild(script);
    });

    loaded.set(src, promise);

    return promise;
}

// -- Google Picker ------------------------------------------------------------

interface PickerData {
    action: string;
    docs?: { id: string }[];
}

interface PickerBuilder {
    addView(view: unknown): PickerBuilder;
    setOAuthToken(token: string): PickerBuilder;
    setDeveloperKey(key: string): PickerBuilder;
    setAppId(appId: string): PickerBuilder;
    setLocale(locale: string): PickerBuilder;
    setCallback(callback: (data: PickerData) => void): PickerBuilder;
    enableFeature(feature: unknown): PickerBuilder;
    setMaxItems(max: number): PickerBuilder;
    build(): { setVisible(visible: boolean): void };
}

interface DocsView {
    setIncludeFolders(include: boolean): unknown;
    setSelectFolderEnabled(enabled: boolean): unknown;
}

interface GooglePickerNamespace {
    PickerBuilder: new () => PickerBuilder;
    DocsView: new (viewId?: unknown) => DocsView;
    ViewId: { DOCS: unknown };
    Feature: { MULTISELECT_ENABLED: unknown };
    Action: { PICKED: string; CANCEL: string };
}

type GoogleWindow = Window & {
    gapi?: {
        load(
            name: string,
            options: { callback: () => void; onerror?: () => void },
        ): void;
    };
    google?: { picker?: GooglePickerNamespace };
};

export async function openGooglePicker(options: {
    token: string;
    apiKey: string;
    appId: string;
    maxItems: number;
    onPicked: (fileIds: string[]) => void;
    onCancel: () => void;
}): Promise<void> {
    await loadScript('https://apis.google.com/js/api.js', {
        id: 'assinavelox-google-api',
    });

    const win = window as GoogleWindow;

    await new Promise<void>((resolve, reject) => {
        if (!win.gapi) {
            reject(new Error('gapi_unavailable'));

            return;
        }

        win.gapi.load('picker', {
            callback: () => resolve(),
            onerror: () => reject(new Error('picker_unavailable')),
        });
    });

    const picker = win.google?.picker;

    if (!picker) {
        throw new Error('picker_unavailable');
    }

    const view = new picker.DocsView(picker.ViewId.DOCS);
    view.setIncludeFolders(false);
    view.setSelectFolderEnabled(false);

    let builder = new picker.PickerBuilder()
        .addView(view)
        .setOAuthToken(options.token)
        .setDeveloperKey(options.apiKey)
        .setAppId(options.appId)
        .setLocale('pt-BR')
        .setCallback((data) => {
            if (data.action === picker.Action.PICKED) {
                options.onPicked((data.docs ?? []).map((doc) => doc.id));
            } else if (data.action === picker.Action.CANCEL) {
                options.onCancel();
            }
        });

    if (options.maxItems > 1) {
        builder = builder
            .enableFeature(picker.Feature.MULTISELECT_ENABLED)
            .setMaxItems(options.maxItems);
    }

    builder.build().setVisible(true);
}

// -- Dropbox Chooser ----------------------------------------------------------

interface DropboxChooserFile {
    link: string;
    name: string;
    id?: string;
    bytes?: number;
}

type DropboxWindow = Window & {
    Dropbox?: {
        choose(options: {
            success: (files: DropboxChooserFile[]) => void;
            cancel?: () => void;
            linkType: 'direct' | 'preview';
            multiselect: boolean;
            extensions?: string[];
            folderselect?: boolean;
            sizeLimit?: number;
        }): void;
    };
};

export async function openDropboxChooser(options: {
    appKey: string;
    multiselect: boolean;
    extensions: string[];
    sizeLimit: number;
    onPicked: (files: PickedDropboxFile[]) => void;
    onCancel: () => void;
}): Promise<void> {
    await loadScript('https://www.dropbox.com/static/api/2/dropins.js', {
        id: 'dropboxjs',
        'data-app-key': options.appKey,
    });

    const dropbox = (window as DropboxWindow).Dropbox;

    if (!dropbox) {
        throw new Error('chooser_unavailable');
    }

    dropbox.choose({
        linkType: 'direct',
        multiselect: options.multiselect,
        folderselect: false,
        extensions: options.extensions.map((extension) => `.${extension}`),
        sizeLimit: options.sizeLimit,
        success: (files) =>
            options.onPicked(
                files.map((file) => ({
                    link: file.link,
                    name: file.name,
                    id: file.id ?? null,
                    bytes: typeof file.bytes === 'number' ? file.bytes : null,
                })),
            ),
        cancel: options.onCancel,
    });
}
