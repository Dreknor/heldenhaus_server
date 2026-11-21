import type { FeatureEntry, FeatureKey, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Freischaltstand (§13) aus den Inertia-Shared-Props. Ohne Daten (z. B. ohne Familie) gilt alles als offen. */
export function useFeatures() {
    const page = usePage<SharedData>();
    const list = computed<FeatureEntry[]>(() => page.props.features?.list ?? []);

    const entry = (key: FeatureKey): FeatureEntry | undefined => list.value.find((f) => f.key === key);
    const isOpen = (key: FeatureKey): boolean => entry(key)?.open ?? true;

    /** Gesperrt, wenn keine der Funktionen offen ist; liefert dann die zuerst kommende. */
    const lockOf = (keys: FeatureKey[]): FeatureEntry | null => {
        const entries = keys.map(entry).filter((e): e is FeatureEntry => !!e);
        if (!entries.length || entries.some((e) => e.open)) return null;
        return [...entries].sort((a, b) => a.level - b.level)[0];
    };

    return { list, entry, isOpen, lockOf };
}
