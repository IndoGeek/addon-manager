import React from 'react';
import { CatalogItem, CatalogPagination } from '../types';
import { CatalogCard } from './CatalogCard';
import { PaginationBar } from './PaginationBar';

interface CatalogResultsProps {
    items: CatalogItem[] | null;
    pagination: CatalogPagination | null;
    searching: boolean;
    catalogError: string | null;
    view: 'grid' | 'list';
    processing: boolean;
    providerLabels: Record<string, string>;
    contentType: string;
    onRetry: () => void;
    onOpenDetails: (item: CatalogItem) => void;
    onPage: (page: number) => void;
}

export const CatalogResults = ({
    items,
    pagination,
    searching,
    catalogError,
    view,
    processing,
    providerLabels,
    contentType,
    onRetry,
    onOpenDetails,
    onPage,
}: CatalogResultsProps) => {
    return (
        <>
            {catalogError && (
                <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--error">
                    <p role="alert">{catalogError}</p>

                    <button
                        type="button"
                        onClick={onRetry}
                    >
                        Retry
                    </button>
                </div>
            )}

            {!catalogError && searching && items === null && (
                <div
                    className="modpackinstaller-catalog-state"
                    role="status"
                >
                    Searching ...
                </div>
            )}

            {!catalogError
                && items !== null
                && items.length === 0
                && !searching && (
                    <div className="modpackinstaller-catalog-state modpackinstaller-catalog-state--empty">
                        <p>
                            Nothing matched your search.
                            Try broadening the filters.
                        </p>
                    </div>
                )}

            {!catalogError
                && items !== null
                && items.length > 0 && (
                    <>
                        <PaginationBar
                            pagination={pagination}
                            searching={searching}
                            onPage={onPage}
                            variant="top"
                        />

                        <div
                            className={`modpackinstaller-catalog-${view}`}
                            aria-busy={searching}
                        >
                            {items.map((item) => (
                                <CatalogCard
                                    key={`${item.provider}:${item.provider_project_id}`}
                                    item={item}
                                    view={view}
                                    providerLabels={providerLabels}
                                    onOpen={onOpenDetails}
                                    disabled={processing || searching}
                                />
                            ))}
                        </div>

                        <PaginationBar
                            pagination={pagination}
                            searching={searching}
                            onPage={onPage}
                            variant="bottom"
                        />
                    </>
                )}
        </>
    );
};
