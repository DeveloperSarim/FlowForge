import { createContext, useContext, useRef, useState } from 'react';
import { Braces, Plus, Trash2 } from 'lucide-react';
import { Button, Field, Input, Select, Switch, Textarea } from '@/Components/ui';
import ScheduleField from '@/Components/Drawer/ScheduleField';
import { cn } from '@/lib/utils';

/**
 * The variable picker inserts into whichever text control was focused last. Each VarInput/VarTextarea
 * registers itself here on focus with an insert(token) that splices at the caret.
 */
export const InsertTargetContext = createContext({ setTarget: () => {}, target: null });

function useInsertTarget(onChange) {
    const { setTarget } = useContext(InsertTargetContext);
    const ref = useRef(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    const register = () => setTarget({
        el: ref.current,
        insert(token) {
            const el = ref.current;
            if (!el) return;
            const start = el.selectionStart ?? el.value.length;
            const end = el.selectionEnd ?? start;
            onChangeRef.current(el.value.slice(0, start) + token + el.value.slice(end));
            requestAnimationFrame(() => {
                el.focus();
                el.setSelectionRange(start + token.length, start + token.length);
            });
        },
    });

    return { ref, onFocus: register };
}

const hasVariable = (value) => typeof value === 'string' && value.includes('{{');

export function VarInput({ value, onChange, className, ...props }) {
    const target = useInsertTarget(onChange);
    return (
        <Input {...props} {...target} value={value ?? ''} onChange={(e) => onChange(e.target.value)}
            className={cn(hasVariable(value) && 'border-info/40 text-info', className)} />
    );
}

export function VarTextarea({ value, onChange, className, ...props }) {
    const target = useInsertTarget(onChange);
    return (
        <Textarea {...props} {...target} value={value ?? ''} onChange={(e) => onChange(e.target.value)}
            className={cn(hasVariable(value) && 'border-info/40', className)} />
    );
}

function JsonField({ value, onChange, id, placeholder }) {
    const [status, setStatus] = useState(null);
    const text = typeof value === 'string' ? value : value == null ? '' : JSON.stringify(value, null, 2);

    const check = () => {
        if (!text.trim()) return setStatus(null);
        try {
            JSON.parse(text);
            setStatus({ ok: true, message: 'Valid JSON' });
        } catch (error) {
            setStatus(hasVariable(text)
                ? { ok: true, message: 'Variables are filled in before the JSON is parsed.' }
                : { ok: false, message: error.message });
        }
    };

    return (
        <div>
            <VarTextarea id={id} mono rows={6} value={text} onChange={onChange} onBlur={check} placeholder={placeholder} spellCheck={false} />
            {status && <p className={cn('mt-1 text-[12px]', status.ok ? 'text-fg-muted' : 'text-fail')}>{status.message}</p>}
        </div>
    );
}

function ListField({ value, onChange, placeholder }) {
    const items = Array.isArray(value) ? value : value ? [value] : [''];
    const set = (index, next) => onChange(items.map((item, i) => (i === index ? next : item)));
    return (
        <div className="space-y-1.5">
            {items.map((item, index) => (
                <div key={index} className="flex gap-1.5">
                    <VarInput value={item} onChange={(next) => set(index, next)} placeholder={placeholder ?? `Value ${index + 1}`} aria-label={`Value ${index + 1}`} />
                    <Button variant="ghost" size="icon" onClick={() => onChange(items.filter((_, i) => i !== index))} aria-label={`Remove value ${index + 1}`}><Trash2 className="size-3.5" /></Button>
                </div>
            ))}
            <Button size="sm" variant="ghost" onClick={() => onChange([...items, ''])}><Plus className="size-3.5" />Add value</Button>
        </div>
    );
}

function KeyValueField({ value, onChange }) {
    const rows = Array.isArray(value) && value.length ? value : [{ key: '', value: '' }];
    const set = (index, patch) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    return (
        <div className="space-y-1.5">
            {rows.map((row, index) => (
                <div key={index} className="grid grid-cols-[1fr_1.4fr_auto] gap-1.5">
                    <VarInput value={row.key} onChange={(key) => set(index, { key })} placeholder="Name" aria-label={`Name ${index + 1}`} />
                    <VarInput value={row.value} onChange={(v) => set(index, { value: v })} placeholder="Value" aria-label={`Value ${index + 1}`} />
                    <Button variant="ghost" size="icon" onClick={() => onChange(rows.filter((_, i) => i !== index))} aria-label={`Remove row ${index + 1}`}><Trash2 className="size-3.5" /></Button>
                </div>
            ))}
            <Button size="sm" variant="ghost" onClick={() => onChange([...rows, { key: '', value: '' }])}><Plus className="size-3.5" />Add row</Button>
        </div>
    );
}

const UNARY = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];
const operatorLabel = (op) => op.replaceAll('_', ' ');

function ConditionsField({ value, onChange, operators }) {
    const rows = Array.isArray(value) && value.length ? value : [{ left: '', operator: 'equals', right: '' }];
    const set = (index, patch) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    return (
        <div className="space-y-2">
            {rows.map((row, index) => (
                <div key={index} className="space-y-1.5 rounded-lg border border-line bg-surface-2 p-2.5">
                    <VarInput value={row.left} onChange={(left) => set(index, { left })} placeholder="{{ steps.trigger.data.plan }}" aria-label={`Condition ${index + 1} value`} />
                    <div className="flex gap-1.5">
                        <Select value={row.operator} onChange={(e) => set(index, { operator: e.target.value })} aria-label={`Condition ${index + 1} operator`} className="w-40 shrink-0">
                            {operators.map((op) => <option key={op} value={op}>{operatorLabel(op)}</option>)}
                        </Select>
                        {!UNARY.includes(row.operator) && (
                            <VarInput value={row.right} onChange={(right) => set(index, { right })} placeholder="Compare to" aria-label={`Condition ${index + 1} comparison`} />
                        )}
                        <Button variant="ghost" size="icon" onClick={() => onChange(rows.filter((_, i) => i !== index))} aria-label={`Remove condition ${index + 1}`}><Trash2 className="size-3.5" /></Button>
                    </div>
                </div>
            ))}
            <Button size="sm" variant="ghost" onClick={() => onChange([...rows, { left: '', operator: 'equals', right: '' }])}><Plus className="size-3.5" />Add condition</Button>
        </div>
    );
}

/** One control per manifest input definition. */
export function InputControl({ input, value, onChange, id, operators = [] }) {
    const current = value ?? input.default;
    switch (input.type) {
        case 'text':
            return <VarTextarea id={id} rows={4} value={current} onChange={onChange} placeholder={input.placeholder} />;
        case 'code':
            return <VarTextarea id={id} mono rows={5} value={current} onChange={onChange} placeholder={input.placeholder} spellCheck={false} />;
        case 'json':
            return <JsonField id={id} value={current} onChange={onChange} placeholder={input.placeholder} />;
        case 'select':
            return (
                <Select id={id} value={current ?? ''} onChange={(e) => onChange(e.target.value)}>
                    {!input.required && input.default === undefined && <option value="">—</option>}
                    {input.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </Select>
            );
        case 'boolean':
            return <Switch checked={current === true || current === 'true'} onChange={onChange} label={input.label} />;
        case 'list':
            return <ListField value={current} onChange={onChange} placeholder={input.placeholder} />;
        case 'keyvalue':
            return <KeyValueField value={current} onChange={onChange} />;
        case 'cron':
            return <ScheduleField id={id} value={value} fallback={input.default} onChange={onChange} />;
        case 'conditions':
            return <ConditionsField value={current} onChange={onChange} operators={operators} />;
        case 'number':
            return <VarInput id={id} inputMode="decimal" value={current == null ? '' : String(current)} onChange={onChange} placeholder={input.placeholder} />;
        case 'password':
            return <VarInput id={id} type="password" autoComplete="off" value={current} onChange={onChange} placeholder={input.placeholder} />;
        default:
            return <VarInput id={id} value={current} onChange={onChange} placeholder={input.placeholder} />;
    }
}

/** Renders a manifest's input list. config is the node's stored values. */
export default function DynamicForm({ inputs, config, onChange, operators, errors = {} }) {
    if (!inputs?.length) {
        return <p className="flex items-center gap-2 text-[13px] text-fg-muted"><Braces className="size-4" />This step has no settings.</p>;
    }
    return (
        <div className="space-y-4">
            {inputs.map((input) => (
                <Field key={input.key} label={input.label} required={input.required} help={input.help} error={errors[input.key]}>
                    {(id) => <InputControl id={id} input={input} value={config?.[input.key]} operators={operators} onChange={(value) => onChange(input.key, value)} />}
                </Field>
            ))}
        </div>
    );
}
