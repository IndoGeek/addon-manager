import React from 'react';
import { CardTag } from '../types';
import { titleCaseTag } from '../utils/constants';

export const PillTags = ({ tags }: { tags: CardTag[] }) => {
    const visibleTags = tags.slice(0, 5);
    const overflowCount = tags.length - visibleTags.length;

    if (tags.length === 0) {
        return null;
    }

    return (
        <div className="modpackinstaller-catalog-card-tags">
            {visibleTags.map((tag) => (
                <span
                    key={tag.label}
                    className={`modpackinstaller-pill${
                        tag.loader
                            ? ' modpackinstaller-pill--loader'
                            : ''
                    }`}
                >
                    {titleCaseTag(tag.label)}
                </span>
            ))}

            {overflowCount > 0 && (
                <span className="modpackinstaller-pill modpackinstaller-pill--overflow">
                    +{overflowCount}
                </span>
            )}
        </div>
    );
};
