import { Fragment, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { cn, toast } from '@/lib/utils';

/** Inline Markdown: **bold**, *italic*, `code`, [text](url), bare URLs and /workflows/ID links. Rendered as elements, never HTML. */
function inline(text, key) {
    return text.split(/(`[^`]+`|\*\*[^*]+\*\*|\[[^\]]+\]\((?:https?:\/\/|\/)[^)\s]+\)|\/workflows\/\d+|https?:\/\/[^\s)]+|\*(?!\s)[^*]+?(?<!\s)\*)/g).map((part, i) => {
        const k = `${key}-${i}`;
        if (!part) return null;
        if (/^`[^`]+`$/.test(part)) return <code key={k} className="rounded bg-surface-3 px-1 py-px font-mono text-[12.5px]">{part.slice(1, -1)}</code>;
        if (/^\*\*.+\*\*$/.test(part)) return <strong key={k}>{part.slice(2, -2)}</strong>;
        const link = part.match(/^\[([^\]]+)\]\(([^)\s]+)\)$/);
        if (link) return link[2].startsWith('/') ? <Link key={k} href={link[2]} className="font-medium text-primary underline-offset-2 hover:underline">{link[1]}</Link>
            : <a key={k} href={link[2]} target="_blank" rel="noreferrer" className="text-primary underline-offset-2 hover:underline">{link[1]}</a>;
        if (/^\/workflows\/\d+$/.test(part)) return <Link key={k} href={part} className="font-medium text-primary underline-offset-2 hover:underline">{part}</Link>;
        if (/^https?:\/\//.test(part)) return <a key={k} href={part} target="_blank" rel="noreferrer" className="break-all text-primary underline-offset-2 hover:underline">{part}</a>;
        if (/^\*.+\*$/.test(part)) return <em key={k}>{part.slice(1, -1)}</em>;
        return <Fragment key={k}>{part}</Fragment>;
    });
}

/** A fenced code block: language label, copy button, and horizontal scroll for long lines. */
function CodeBlock({ lang, code }) {
    const [copied, setCopied] = useState(false);
    const copy = () => navigator.clipboard?.writeText(code).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }, () => toast('Select the code and copy it.', 'error'));
    return (
        <div className="my-2 overflow-hidden rounded-xl border border-line bg-surface-2">
            <div className="flex items-center justify-between border-b border-line px-3 py-1.5 text-[11.5px] text-fg-muted">
                <span className="font-mono">{lang || 'code'}</span>
                <button type="button" onClick={copy} className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium hover:bg-surface-3 hover:text-fg">
                    {copied ? <Check className="size-3.5 text-ok" /> : <Copy className="size-3.5" />}{copied ? 'Copied' : 'Copy'}
                </button>
            </div>
            <pre className="overflow-x-auto px-3 py-2.5 font-mono text-[12.5px] leading-relaxed"><code>{code}</code></pre>
        </div>
    );
}

const cells = (row) => row.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());

/**
 * Safe Markdown for AI answers and release notes: paragraphs, headings, lists, quotes, rules, tables and ``` code blocks
 * (an unclosed block still shows as code while the answer streams). Built from elements, so nothing becomes HTML.
 */
export default function RichText({ text, className }) {
    const lines = text.split('\n');
    const blocks = [];
    let list = [];
    let ordered = false;
    const flush = (key) => {
        if (!list.length) return;
        blocks.push(ordered
            ? <ol key={key} className="my-1.5 list-decimal space-y-0.5 pl-5">{list}</ol>
            : <ul key={key} className="my-1.5 list-disc space-y-0.5 pl-5">{list}</ul>);
        list = [];
    };

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const fence = line.match(/^\s*(```|~~~)\s*([\w#+.-]*)/);
        if (fence) {
            flush(`l${i}`);
            const body = [];
            let j = i + 1;
            while (j < lines.length && !lines[j].trim().startsWith(fence[1])) body.push(lines[j++]);
            blocks.push(<CodeBlock key={i} lang={fence[2]} code={body.join('\n')} />);
            i = j;
            continue;
        }
        // A table: a header row, a |---| separator, then rows.
        if (line.includes('|') && /^\s*\|?\s*:?-{3,}/.test(lines[i + 1] ?? '')) {
            flush(`l${i}`);
            const head = cells(line);
            const rows = [];
            let j = i + 2;
            while (j < lines.length && lines[j].includes('|') && lines[j].trim()) rows.push(cells(lines[j++]));
            blocks.push(
                <div key={i} className="my-2 overflow-x-auto rounded-xl border border-line">
                    <table className="w-full text-left text-[13px]">
                        <thead className="bg-surface-2"><tr>{head.map((h, c) => <th key={c} className="px-3 py-1.5 font-semibold">{inline(h, `${i}h${c}`)}</th>)}</tr></thead>
                        <tbody className="divide-y divide-line">{rows.map((r, ri) => <tr key={ri}>{r.map((v, c) => <td key={c} className="px-3 py-1.5 align-top">{inline(v, `${i}r${ri}c${c}`)}</td>)}</tr>)}</tbody>
                    </table>
                </div>,
            );
            i = j - 1;
            continue;
        }
        const bullet = line.match(/^\s*[-*•]\s+(.*)/);
        const numbered = line.match(/^\s*\d+[.)]\s+(.*)/);
        if (bullet || numbered) {
            if (list.length && ordered !== !!numbered) flush(`l${i}`);
            ordered = !!numbered;
            list.push(<li key={i} className={/^\s{2,}/.test(line) ? 'ml-5 list-[circle]' : undefined}>{inline((bullet ?? numbered)[1], i)}</li>);
            continue;
        }
        flush(`l${i}`);
        const heading = line.match(/^(#{1,4})\s+(.*)/);
        if (heading) blocks.push(<p key={i} className={cn('mb-1 font-semibold', heading[1].length <= 2 ? 'mt-3 text-[15px]' : 'mt-2.5')}>{inline(heading[2], i)}</p>);
        else if (/^\s*>\s?/.test(line)) blocks.push(<blockquote key={i} className="my-1.5 border-l-2 border-line-strong pl-3 text-fg-muted">{inline(line.replace(/^\s*>\s?/, ''), i)}</blockquote>);
        else if (/^\s*(-{3,}|\*{3,})\s*$/.test(line)) blocks.push(<hr key={i} className="my-3 border-line" />);
        else if (line.trim()) blocks.push(<p key={i} className="my-1">{inline(line, i)}</p>);
    }
    flush('last');
    return <div className={cn('min-w-0 text-[14px] leading-relaxed', className)}>{blocks}</div>;
}
