import React from 'react';
import { ManualDownloadInfo } from '../types';

export const ManualDownloadNotice = ({
    manual,
}: {
    manual: ManualDownloadInfo;
}) => {
    return (
        <div
            className="modpackinstaller-manual-download"
            role="alert"
        >
            <h4>Manual download required</h4>

            <p>{manual.reason}</p>

            <div className="modpackinstaller-details">
                <div>
                    <span>Provider</span>
                    <strong>{manual.provider}</strong>
                </div>

                <div>
                    <span>Project</span>
                    <strong>{manual.project_name}</strong>
                </div>

                <div>
                    <span>File</span>
                    <strong>{manual.file_name}</strong>
                </div>

                <div>
                    <span>Version</span>
                    <strong>{manual.version}</strong>
                </div>
            </div>

            {manual.download_url && (
                <p className="modpackinstaller-manual-download-link">
                    <a
                        href={manual.download_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open official download
                    </a>
                </p>
            )}

            {manual.project_url && (
                <p className="modpackinstaller-manual-download-link">
                    <a
                        href={manual.project_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        View project on CurseForge
                    </a>
                </p>
            )}
        </div>
    );
};
