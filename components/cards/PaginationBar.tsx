import React from 'react';
import { CatalogPagination } from '../types';
import { ChevronLeftIcon, ChevronRightIcon } from '../icons';

export const PaginationBar = ({
    pagination,
    searching,
    onPage,
    variant,
}: {
    pagination: CatalogPagination | null;
    searching: boolean;
    onPage: (page: number) => void;
    variant: 'top' | 'bottom';
}) => {
    if (!pagination) {
        return null;
    }

    const page = pagination.page;

    return (
        <nav
            className={`modpackinstaller-pagination modpackinstaller-pagination--${variant}`}
            aria-label="Catalog pages"
            aria-busy={searching}
        >
            <span
                className="modpackinstaller-pagination-results"
                aria-label={`${pagination.total} ${
                    pagination.total === 1
                        ? 'result'
                        : 'results'
                }`}
            >
                {pagination.total}{' '}
                {pagination.total === 1
                    ? 'result'
                    : 'results'}
            </span>

            <div className="modpackinstaller-pagination-actions">
                <button
                    type="button"
                    className="modpackinstaller-pagination-button"
                    onClick={() => onPage(page - 1)}
                    disabled={!pagination.has_previous || searching}
                    aria-label="Previous page"
                    title="Previous page"
                >
                    <ChevronLeftIcon />
                </button>

                <span className="modpackinstaller-pagination-page">
                    Page {page} of {pagination.total_pages}
                </span>

                <button
                    type="button"
                    className="modpackinstaller-pagination-button"
                    onClick={() => onPage(page + 1)}
                    disabled={!pagination.has_next || searching}
                    aria-label="Next page"
                    title="Next page"
                >
                    <ChevronRightIcon />
                </button>
            </div>
        </nav>
    );
};
