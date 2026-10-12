import { useCallback, useEffect, useState } from 'react';
import { errorMessage } from '@/lib/utils';

// Full manifests are fetched on demand (the palette only ships a lightweight catalog) and cached per page load.
const cache = new Map();

export function fetchIntegration(key, { fresh = false } = {}) {
    if (fresh || !cache.has(key)) {
        cache.set(key, window.axios.get(`/integrations/${encodeURIComponent(key)}`).then((r) => r.data.integration).catch((error) => {
            cache.delete(key);
            throw error;
        }));
    }
    return cache.get(key);
}

export function useIntegration(key) {
    const [state, setState] = useState({ integration: null, loading: !!key, error: null });

    const load = useCallback((fresh = false) => {
        if (!key) return setState({ integration: null, loading: false, error: null });
        setState((s) => ({ ...s, loading: true, error: null }));
        fetchIntegration(key, { fresh })
            .then((integration) => setState({ integration, loading: false, error: null }))
            .catch((error) => setState({ integration: null, loading: false, error: errorMessage(error) }));
    }, [key]);

    useEffect(() => load(), [load]);

    return { ...state, reload: () => load(true) };
}
