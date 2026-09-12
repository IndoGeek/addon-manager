import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

interface ModpackMetadata {
    id: string;
    name: string;
    version: string;
    minecraft_version: string;
    loader: string;
    description: string | null;
    icon_url: string | null;
    source: string;
}

interface MetadataResponse {
    data: ModpackMetadata;
}

interface PreviewOperation {
    path: string;
    action: string;
}

interface InstallationPreview {
    total_files: number;
    create_count: number;
    overwrite_count: number;
    operations: PreviewOperation[];
}

interface PreviewResponse {
    data: InstallationPreview;
}

interface InstallationResult {
    total_files: number;
    created: number;
    overwritten: number;
    backed_up: number;
}

interface InstallResponse {
    data: InstallationResult;
}

interface StatusMessage {
    kind: 'error' | 'info' | 'success';
    message: string;
}

const API_BASE =
    '/api/client/extensions/modpackinstaller';

const getServerIdentifier = (): string | null => {
    const match = window.location.pathname.match(
        /^\/server\/([^/]+)/,
    );

    return match?.[1] ?? null;
};

export default () => {
    const server = getServerIdentifier();

    const alive = useRef(true);

    useEffect(() => {
        return () => {
            alive.current = false;
        };
    }, []);

    const [source, setSource] =
        useState('mock://example-pack');

    const [metadata, setMetadata] =
        useState<ModpackMetadata | null>(null);

    const [selectedSource, setSelectedSource] =
        useState<string | null>(null);

    const [policy, setPolicy] =
        useState('overwrite');

    const [layout, setLayout] =
        useState('direct');

    const [preview, setPreview] =
        useState<InstallationPreview | null>(null);

    const [result, setResult] =
        useState<InstallationResult | null>(null);

    const [loading, setLoading] =
        useState(false);

    const [previewLoading, setPreviewLoading] =
        useState(false);

    const [installLoading, setInstallLoading] =
        useState(false);

    const [status, setStatus] =
        useState<StatusMessage | null>(null);

    const busy = loading || previewLoading || installLoading;

    const updateSource = (value: string) => {
        setSource(value);
        setPreview(null);
        setResult(null);
        setStatus(null);

        if (value.trim() !== selectedSource) {
            setMetadata(null);
            setSelectedSource(null);
        }
    };

    const selectLayout = (value: string) => {
        setLayout(value);
        setPreview(null);
        setResult(null);
        setStatus(null);
    };

    const selectPolicy = (value: string) => {
        setPolicy(value);
        setPreview(null);
        setResult(null);
        setStatus(null);
    };

    const loadMetadata = async () => {
        const trimmedSource = source.trim();

        if (!trimmedSource) {
            setStatus({
                kind: 'error',
                message: 'Please enter a modpack source.',
            });
            setMetadata(null);
            setSelectedSource(null);
            setPreview(null);
            setResult(null);
            return;
        }

        if (loading) {
            return;
        }

        setLoading(true);
        setStatus(null);
        setMetadata(null);
        setSelectedSource(null);
        setPreview(null);
        setResult(null);

        try {
            const response =
                await axios.get<MetadataResponse>(
                    `${API_BASE}/metadata`,
                    {
                        params: {
                            source: trimmedSource,
                        },
                    },
                );

            if (!alive.current) {
                return;
            }

            setMetadata(response.data.data);
            setSelectedSource(trimmedSource);
            setStatus({
                kind: 'info',
                message: `Metadata loaded for ${trimmedSource}.`,
            });
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to load modpack metadata.';

            setStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (alive.current) {
                setLoading(false);
            }
        }
    };

    const previewInstallation = async () => {
        if (!server) {
            setStatus({
                kind: 'error',
                message:
                    'Unable to determine the current server.',
            });
            return;
        }

        if (!selectedSource) {
            setStatus({
                kind: 'error',
                message:
                    'Load a modpack before previewing installation.',
            });
            return;
        }

        if (previewLoading || installLoading) {
            return;
        }

        setPreviewLoading(true);
        setStatus(null);
        setPreview(null);
        setResult(null);

        try {
            const response =
                await axios.post<PreviewResponse>(
                    `${API_BASE}/servers/${server}/preview`,
                    {
                        source: selectedSource,
                        policy,
                        layout,
                    },
                );

            if (!alive.current) {
                return;
            }

            setPreview(response.data.data);
            setStatus({
                kind: 'info',
                message:
                    'Installation preview is ready. Review the operations below.',
            });
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to preview the modpack installation.';

            setStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (alive.current) {
                setPreviewLoading(false);
            }
        }
    };

    const installModpack = async () => {
        if (!server) {
            setStatus({
                kind: 'error',
                message:
                    'Unable to determine the current server.',
            });
            return;
        }

        if (!selectedSource) {
            setStatus({
                kind: 'error',
                message:
                    'Load a modpack before installing.',
            });
            return;
        }

        if (!preview) {
            setStatus({
                kind: 'error',
                message:
                    'Preview the installation before installing.',
            });
            return;
        }

        if (installLoading) {
            return;
        }

        setInstallLoading(true);
        setStatus(null);
        setResult(null);

        try {
            const response =
                await axios.post<InstallResponse>(
                    `${API_BASE}/servers/${server}/install`,
                    {
                        source: selectedSource,
                        policy,
                        layout,
                    },
                );

            if (!alive.current) {
                return;
            }

            setResult(response.data.data);
            setPreview(null);
            setStatus({
                kind: 'success',
                message: 'Installation complete.',
            });
        } catch (requestError: any) {
            if (!alive.current) {
                return;
            }

            const message =
                requestError.response?.data?.error ||
                'Unable to install the modpack.';

            setStatus({
                kind: 'error',
                message,
            });
        } finally {
            if (alive.current) {
                setInstallLoading(false);
            }
        }
    };

    return (
        <div
            className="modpackinstaller-root"
            aria-busy={busy}
        >
            <div className="modpackinstaller-header">
                <h2>Modpack Installer</h2>

                <p>
                    Install a modpack directly onto this server.
                </p>
            </div>

            <div className="modpackinstaller-card">
                <h3>Choose a modpack</h3>

                <p>
                    Enter a modpack source to retrieve its metadata.
                </p>

                <div className="modpackinstaller-form">
                    <label htmlFor="modpackinstaller-source">
                        Modpack source
                    </label>

                    <input
                        id="modpackinstaller-source"
                        type="text"
                        value={source}
                        onChange={(event) =>
                            updateSource(event.target.value)
                        }
                        placeholder="mock://example-pack"
                        disabled={loading}
                    />

                    <button
                        type="button"
                        onClick={loadMetadata}
                        disabled={busy}
                    >
                        {loading
                            ? 'Loading ...'
                            : metadata
                                ? 'Reload Modpack'
                                : 'Load Modpack'}
                    </button>

                    <p className="modpackinstaller-source-hint">
                        Sources: modrinth://project-slug ·
                        curseforge://project-id · mock://example-pack
                    </p>
                </div>

                {status && (
                    <div
                        className={`modpackinstaller-status modpackinstaller-status--${status.kind}`}
                        role={
                            status.kind === 'error'
                                ? 'alert'
                                : 'status'
                        }
                    >
                        {status.message}
                    </div>
                )}

                {metadata && (
                    <div className="modpackinstaller-metadata">
                        <div className="modpackinstaller-metadata-header">
                            {metadata.icon_url && (
                                <img
                                    src={metadata.icon_url}
                                    alt=""
                                    className="modpackinstaller-icon"
                                />
                            )}

                            <div>
                                <h3>{metadata.name}</h3>

                                {metadata.description && (
                                    <p>
                                        {metadata.description}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="modpackinstaller-selected">
                            Selected modpack
                        </div>

                        <div className="modpackinstaller-details">
                            <div>
                                <span>Version</span>
                                <strong>
                                    {metadata.version}
                                </strong>
                            </div>

                            <div>
                                <span>Minecraft</span>
                                <strong>
                                    {metadata.minecraft_version}
                                </strong>
                            </div>

                            <div>
                                <span>Loader</span>
                                <strong>
                                    {metadata.loader}
                                </strong>
                            </div>
                        </div>

                        <div className="modpackinstaller-source">
                            <span>Source</span>
                            <code>
                                {metadata.source}
                            </code>
                        </div>
                    </div>
                )}
            </div>

            {metadata && (
                <div className="modpackinstaller-card">
                    <h3>Installation options</h3>

                    <div className="modpackinstaller-options">
                        <fieldset>
                            <legend>Package layout</legend>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-layout"
                                    value="direct"
                                    checked={
                                        layout === 'direct'
                                    }
                                    onChange={() =>
                                        selectLayout('direct')
                                    }
                                />

                                Direct
                            </label>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-layout"
                                    value="overrides"
                                    checked={
                                        layout === 'overrides'
                                    }
                                    onChange={() =>
                                        selectLayout(
                                            'overrides',
                                        )
                                    }
                                />

                                Overrides
                            </label>
                        </fieldset>

                        <fieldset>
                            <legend>Existing files</legend>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-policy"
                                    value="overwrite"
                                    checked={
                                        policy === 'overwrite'
                                    }
                                    onChange={() =>
                                        selectPolicy('overwrite')
                                    }
                                />

                                Overwrite
                            </label>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-policy"
                                    value="skip_existing"
                                    checked={
                                        policy === 'skip_existing'
                                    }
                                    onChange={() =>
                                        selectPolicy(
                                            'skip_existing',
                                        )
                                    }
                                />

                                Skip existing
                            </label>

                            <label>
                                <input
                                    type="radio"
                                    name="modpackinstaller-policy"
                                    value="create_only"
                                    checked={
                                        policy === 'create_only'
                                    }
                                    onChange={() =>
                                        selectPolicy(
                                            'create_only',
                                        )
                                    }
                                />

                                Create only
                            </label>
                        </fieldset>
                    </div>

                    <button
                        type="button"
                        onClick={previewInstallation}
                        disabled={
                            previewLoading ||
                            installLoading
                        }
                    >
                        {previewLoading
                            ? 'Preparing Preview ...'
                            : 'Preview Installation'}
                    </button>
                </div>
            )}

            {preview && (
                <div className="modpackinstaller-card">
                    <h3>Installation Preview</h3>

                    <div className="modpackinstaller-details">
                        <div>
                            <span>Total files</span>
                            <strong>
                                {preview.total_files}
                            </strong>
                        </div>

                        <div>
                            <span>New files</span>
                            <strong>
                                {preview.create_count}
                            </strong>
                        </div>

                        <div>
                            <span>Overwrite</span>
                            <strong>
                                {preview.overwrite_count}
                            </strong>
                        </div>
                    </div>

                    <div className="modpackinstaller-operation-list">
                        {preview.operations.map(
                            (operation) => (
                                <div
                                    key={`${operation.action}:${operation.path}`}
                                >
                                    <code>
                                        {operation.path}
                                    </code>

                                    <span>
                                        {operation.action}
                                    </span>
                                </div>
                            ),
                        )}
                    </div>

                    <button
                        type="button"
                        onClick={installModpack}
                        disabled={
                            installLoading ||
                            previewLoading
                        }
                    >
                        {installLoading
                            ? 'Installing ...'
                            : 'Install Modpack'}
                    </button>
                </div>
            )}

            {result && (
                <div className="modpackinstaller-card">
                    <h3>Installation complete</h3>

                    <div className="modpackinstaller-result-grid">
                        <div>
                            <span>Total files</span>
                            <strong>
                                {result.total_files}
                            </strong>
                        </div>

                        <div>
                            <span>Created</span>
                            <strong>
                                {result.created}
                            </strong>
                        </div>

                        <div>
                            <span>Overwritten</span>
                            <strong>
                                {result.overwritten}
                            </strong>
                        </div>

                        <div>
                            <span>Backups</span>
                            <strong>
                                {result.backed_up}
                            </strong>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};