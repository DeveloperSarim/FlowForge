import { useMemo, useState } from 'react';
import { LayoutTemplate } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import TemplateCard from '@/Components/TemplateCard';
import { Card, EmptyState, SearchInput } from '@/Components/ui';
import { cn } from '@/lib/utils';

export default function Index({ templates }) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('All');

    const categories = useMemo(() => ['All', ...new Set(templates.map((t) => t.category))], [templates]);
    const visible = templates.filter((t) => {
        const q = query.trim().toLowerCase();
        return (category === 'All' || t.category === category)
            && (!q || t.name.toLowerCase().includes(q) || t.description.toLowerCase().includes(q) || t.apps.some((a) => a.name.toLowerCase().includes(q)));
    });

    return (
        <AppLayout title="Templates" description="Ready-made workflows. Use one, connect your credentials on the highlighted steps, and test it.">
            <div className="mb-5 flex flex-wrap items-center gap-3">
                <SearchInput value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search templates or apps" className="w-full sm:w-80" aria-label="Search templates" />
                <div className="flex flex-wrap gap-1.5">
                    {categories.map((c) => (
                        <button key={c} type="button" onClick={() => setCategory(c)} aria-pressed={category === c}
                            className={cn('rounded-full border px-3 py-1 text-13 font-medium transition-colors',
                                category === c ? 'border-primary bg-primary-soft text-primary' : 'border-line bg-surface text-fg-muted hover:text-fg')}>
                            {c}
                        </button>
                    ))}
                </div>
            </div>
            {visible.length === 0 ? (
                <Card><EmptyState icon={LayoutTemplate} title="No templates match">Try another search or category.</EmptyState></Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {visible.map((t) => <TemplateCard key={t.key} template={t} />)}
                </div>
            )}
        </AppLayout>
    );
}
