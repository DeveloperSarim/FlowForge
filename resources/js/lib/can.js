import { usePage } from '@inertiajs/react';

/** What the signed-in person may do: { admin, 'workflows.view', 'workflows.edit', 'workflows.delete', ai }. The server enforces it too. */
export const useCan = () => usePage().props.auth?.user?.can ?? {};
