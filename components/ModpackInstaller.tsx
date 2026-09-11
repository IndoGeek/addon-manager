import React, { useState } from 'react';
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

export default () => {
    const [source, setSource] = useState('mock://example-pack');
    const [metadata, setMetadata] = useState<ModpackMetadata | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadMetadata = async () => {
        const trimmedSource = source.trim();

        if (!trimmedSource) {
            setError('Please enter a modpack source.');
            setMetadata(null);
            return;
        }

        setLoading(true);
        setError(null);
        setMetadata(null);

        try {
            const response = await axios.get<MetadataResponse>(
                '/api/client/extensions/modpackinstaller/metadata',
                {
                    params: {
                        source: trimmedSource,
                    },
                }
            );

            setMetadata(response.data.data);
        } catch (requestError: any) {
            const message =
                requestError?.response?.data?.error ||
                'Unable to load modpack metadata.';

            setError(message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="modpackinstaller-page">
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
                        onChange={(event) => setSource(event.target.value)}
                        placeholder="mock://example-pack"
                    />

                    <button
                        type="button"
                        onClick={loadMetadata}
                        disabled={loading}
                    >
                        {loading ? 'Loading...' : 'Load Modpack'}
                    </button>
                </div>

                {error && (
                    <div className="modpackinstaller-error">
                        {error}
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
                                    <p>{metadata.description}</p>
                                )}
                            </div>
                        </div>

                        <div className="modpackinstaller-details">
                            <div>
                                <span>Version</span>
                                <strong>{metadata.version}</strong>
                            </div>

                            <div>
                                <span>Minecraft</span>
                                <strong>
                                    {metadata.minecraft_version}
                                </strong>
                            </div>

                            <div>
                                <span>Loader</span>
                                <strong>{metadata.loader}</strong>
                            </div>
                        </div>

                        <div className="modpackinstaller-source">
                            <span>Source</span>
                            <code>{metadata.source}</code>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};
