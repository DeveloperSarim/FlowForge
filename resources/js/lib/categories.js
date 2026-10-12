import {
    BarChart3, Bitcoin, Boxes, CalendarCheck, CalendarDays, ClipboardList, Code2, CreditCard, Cpu, Database, FileText, Film, GitBranch,
    Globe, GraduationCap, Handshake, LifeBuoy, Megaphone, MessageSquare, Search, Share2, ShieldCheck, ShoppingCart, Sparkles,
    SquareKanban, Users, Wand2, Wrench, Zap,
} from 'lucide-react';

/** Display order + icon + accent for every integration category. */
export const CATEGORIES = [
    { name: 'Triggers', icon: Zap, color: '#f59e0b' },
    { name: 'Core', icon: Boxes, color: '#64748b' },
    { name: 'Flow control', icon: GitBranch, color: '#8b5cf6' },
    { name: 'Data transformation', icon: Wand2, color: '#0ea5a4' },
    { name: 'AI', icon: Sparkles, color: '#a855f7' },
    { name: 'Communication', icon: MessageSquare, color: '#3b82f6' },
    { name: 'Marketing & Email', icon: Megaphone, color: '#ec4899' },
    { name: 'CRM & Sales', icon: Handshake, color: '#f97316' },
    { name: 'Customer Support', icon: LifeBuoy, color: '#06b6d4' },
    { name: 'Productivity', icon: CalendarCheck, color: '#22c55e' },
    { name: 'Project Management', icon: SquareKanban, color: '#6366f1' },
    { name: 'Files & Documents', icon: FileText, color: '#0ea5e9' },
    { name: 'Developer Tools', icon: Code2, color: '#475569' },
    { name: 'Databases', icon: Database, color: '#14b8a6' },
    { name: 'E-commerce', icon: ShoppingCart, color: '#84cc16' },
    { name: 'Payments & Finance', icon: CreditCard, color: '#10b981' },
    { name: 'Social Media', icon: Share2, color: '#e11d48' },
    { name: 'Analytics', icon: BarChart3, color: '#f59e0b' },
    { name: 'Forms & Surveys', icon: ClipboardList, color: '#d946ef' },
    { name: 'HR & Recruiting', icon: Users, color: '#fb7185' },
    { name: 'CMS & Websites', icon: Globe, color: '#0284c7' },
    { name: 'Ads & SEO', icon: Search, color: '#ea580c' },
    { name: 'Video & Media', icon: Film, color: '#db2777' },
    { name: 'Security & IT', icon: ShieldCheck, color: '#0f766e' },
    { name: 'Events & Webinars', icon: CalendarDays, color: '#7c3aed' },
    { name: 'Education', icon: GraduationCap, color: '#2563eb' },
    { name: 'IoT & Smart Home', icon: Cpu, color: '#059669' },
    { name: 'Crypto & Web3', icon: Bitcoin, color: '#d97706' },
    { name: 'Utilities', icon: Wrench, color: '#78716c' },
];

const BY_NAME = Object.fromEntries(CATEGORIES.map((c, i) => [c.name, { ...c, order: i }]));

export const categoryMeta = (name) => BY_NAME[name] ?? { name, icon: Boxes, color: '#64748b', order: 99 };

/** Built-in nodes (not third-party apps). */
export const CORE_CATEGORIES = ['Triggers', 'Core', 'Flow control', 'Data transformation'];
