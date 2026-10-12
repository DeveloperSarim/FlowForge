import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ChevronRight, Zap } from 'lucide-react';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { Button, Card } from '@/Components/ui';
import { useCan } from '@/lib/can';

/** Template card: the apps it chains, what it does, and a one-click "Use template". */
export default function TemplateCard({ template }) {
    const can = useCan();
    const [using, setUsing] = useState(false);
    const apps = template.apps.length ? template.apps : [];

    return (
        <Card className="group flex flex-col p-5 transition-shadow hover:shadow-pop">
            <div className="flex items-center gap-1.5">
                {apps.length === 0 && <span className="flex size-9 items-center justify-center rounded-lg bg-primary-soft text-primary"><Zap className="size-4" /></span>}
                {apps.slice(0, 4).map((app, i) => (
                    <span key={app.key} className="flex items-center gap-1.5">
                        {i > 0 && <ChevronRight className="size-3.5 text-fg-subtle" />}
                        <IntegrationIcon icon={app.icon} name={app.name} size="md" />
                    </span>
                ))}
            </div>
            <h3 className="mt-4 text-[14.5px] font-semibold leading-snug">{template.name}</h3>
            <p className="mt-1 line-clamp-2 flex-1 text-[13px] text-fg-muted">{template.description}</p>
            <div className="mt-4 flex items-center justify-between">
                <span className="text-[12px] text-fg-subtle">{template.category} · {template.steps} steps</span>
                {can['workflows.edit'] && <Button size="sm" variant="outline" loading={using} onClick={() => { setUsing(true); router.post(`/templates/${template.key}/use`, {}, { onFinish: () => setUsing(false) }); }}>
                    Use template
                </Button>}
            </div>
        </Card>
    );
}
