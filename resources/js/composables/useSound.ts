import type { Feedback, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * Klänge (Konzept §2.9 G4, §11.4): kurze CC0-Klänge aus Kenneys „Interface Sounds“ in public/sounds.
 * Sie spielen nur mit `sound = true` und nie bei der Rückmeldung „nur Haptik“. Die Lautstärke ist dezent,
 * bei „dezent“ noch leiser.
 */
export type SoundName = 'task' | 'step' | 'levelup' | 'drop' | 'unlock' | 'error';

const VOLUME = { full: 0.35, subtle: 0.15 } as const;
const cache = new Map<SoundName, HTMLAudioElement>();
let oggSupported: boolean | null = null;

export const DEFAULT_FEEDBACK: Feedback = { level: 'full', reduce_motion: false, sound: true, haptics: true };

export function soundAllowed(feedback: Feedback): boolean {
    return feedback.sound && feedback.level !== 'haptic';
}

/**
 * Spielt einen Klang. Kann der Browser kein Ogg (oder blockiert er das Abspielen), läuft `fallback`,
 * z. B. die synthetische Glocke aus useCelebrate.
 */
export function playSound(name: SoundName, feedback: Feedback, fallback?: () => void): void {
    if (typeof window === 'undefined' || !soundAllowed(feedback)) return;
    try {
        oggSupported ??= typeof Audio !== 'undefined' && new Audio().canPlayType('audio/ogg; codecs="vorbis"') !== '';
        if (!oggSupported) {
            fallback?.();
            return;
        }
        let base = cache.get(name);
        if (!base) {
            base = new Audio(`/sounds/${name}.ogg`);
            base.preload = 'auto';
            cache.set(name, base);
        }
        // Eigene Kopie, damit schnelle Klicks sich nicht gegenseitig abschneiden
        const audio = base.cloneNode(true) as HTMLAudioElement;
        audio.volume = feedback.level === 'subtle' ? VOLUME.subtle : VOLUME.full;
        void audio.play().catch(() => fallback?.());
    } catch {
        fallback?.();
    }
}

/** Klang mit den Einstellungen der angemeldeten Person. */
export function useSound() {
    const page = usePage<SharedData>();

    function feedback(): Feedback {
        return page.props.auth?.feedback ?? DEFAULT_FEEDBACK;
    }

    return {
        play: (name: SoundName, fallback?: () => void) => playSound(name, feedback(), fallback),
        feedback,
    };
}
