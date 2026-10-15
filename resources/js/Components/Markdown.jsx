import { Fragment } from 'react';
import { cn } from '@/lib/utils';

// The Markdown that API descriptions (OpenAPI is CommonMark) actually use: **bold**, *italic*, `code`, [links](https://…),
// lists, headings and paragraphs. Rendered as React elements, never as HTML, so a description can't inject markup.
const INLINE = /(\*\*[^*\n]+\*\*|`[^`\n]+`|\[[^\]\n]+\]\((?:https?:\/\/|mailto:)[^)\s]+\)|\*(?!\s)[^*\n]+?(?<!\s)\*)/g;
const LIST_ITEM = /^\s*(?:[-*+]|\d+[.)])\s+/;

function inline(text, key) {
    return text.split(INLINE).map((part, i) => {
        if (!part) return null;
        const k = `${key}-${i}`;
        if (part.length > 4 && part.startsWith('**') && part.endsWith('**')) return <strong key={k} className="font-semibold text-fg">{part.slice(2, -2)}</strong>;
        if (part.length > 2 && part.startsWith('`') && part.endsWith('`')) return <code key={k} className="rounded bg-surface-2 px-1 py-px font-mono text-[0.92em] text-fg">{part.slice(1, -1)}</code>;
        const link = part.match(/^\[([^\]]+)\]\(([^)\s]+)\)$/);
        if (link) return <a key={k} href={link[2]} target="_blank" rel="noreferrer" className="font-medium text-primary hover:underline">{link[1]}</a>;
        if (part.length > 2 && part.startsWith('*') && part.endsWith('*')) return <em key={k}>{part.slice(1, -1)}</em>;
        return <Fragment key={k}>{part}</Fragment>;
    });
}

/** `inline` keeps it to one run of text (for help lines); otherwise paragraphs and lists become blocks. */
export default function Markdown({ text, className, inline: asInline = false }) {
    if (!text) return null;
    if (asInline) return <span className={className}>{inline(String(text).replace(/\s*\n\s*/g, ' '), 'i')}</span>;

    return (
        <div className={cn('space-y-1.5', className)}>
            {String(text).trim().split(/\n\s*\n/).map((block, b) => {
                const lines = block.split('\n');
                if (lines.every((line) => LIST_ITEM.test(line))) {
                    const Tag = /^\s*\d/.test(lines[0]) ? 'ol' : 'ul';
                    return (
                        <Tag key={b} className={cn('space-y-0.5 pl-5', Tag === 'ol' ? 'list-decimal' : 'list-disc')}>
                            {lines.map((line, i) => <li key={i}>{inline(line.replace(LIST_ITEM, ''), `${b}-${i}`)}</li>)}
                        </Tag>
                    );
                }
                return (
                    <p key={b}>
                        {lines.map((line, i) => {
                            const heading = line.match(/^\s*#{1,6}\s+(.*)$/);
                            return (
                                <Fragment key={i}>
                                    {i > 0 && <br />}
                                    {heading ? <strong className="font-semibold text-fg">{inline(heading[1], `${b}-${i}`)}</strong> : inline(line, `${b}-${i}`)}
                                </Fragment>
                            );
                        })}
                    </p>
                );
            })}
        </div>
    );
}

/** The same text without Markdown marks, for one-line subtitles and cards. */
export const plainText = (text) => String(text ?? '')
    .replace(/\*\*([^*\n]+)\*\*/g, '$1').replace(/`([^`\n]+)`/g, '$1').replace(/\[([^\]\n]+)\]\([^)\s]+\)/g, '$1')
    .replace(/^\s*#{1,6}\s+/gm, '').replace(/\s+/g, ' ').trim();
