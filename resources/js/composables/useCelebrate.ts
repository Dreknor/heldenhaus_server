import type { Feedback, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * Sofortige Rückmeldung beim Abhaken (Konzept §11.1, §11.4): Konfetti, ein kurzer Klang und Haptik.
 * Die Stärke folgt den Fokus-Einstellungen der Person (voll / dezent / nur Haptik, Animationen, Ton)
 * und der Systemeinstellung „Bewegung reduzieren“. Ohne Abhängigkeiten: Canvas und Web Audio.
 */
export type CelebrateKind = 'step' | 'task' | 'big';

const COLORS = ['#f59e0b', '#ec4899', '#8b5cf6', '#10b981', '#38bdf8', '#f43f5e', '#facc15'];
// Pentatonik klingt immer freundlich, egal in welcher Reihenfolge
const NOTES: Record<CelebrateKind, number[]> = {
    step: [659.25, 880],
    task: [523.25, 659.25, 783.99, 1046.5],
    big: [523.25, 659.25, 783.99, 1046.5, 1318.5, 1567.98],
};

let audio: AudioContext | null = null;

function playChime(kind: CelebrateKind, soft: boolean) {
    try {
        audio ??= new (window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext)();
        if (audio.state === 'suspended') void audio.resume();
        const now = audio.currentTime;
        NOTES[kind].forEach((freq, i) => {
            const osc = audio!.createOscillator();
            const gain = audio!.createGain();
            osc.type = 'triangle';
            osc.frequency.value = freq;
            const start = now + i * 0.075;
            gain.gain.setValueAtTime(0, start);
            gain.gain.linearRampToValueAtTime(soft ? 0.05 : 0.12, start + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.45);
            osc.connect(gain).connect(audio!.destination);
            osc.start(start);
            osc.stop(start + 0.5);
        });
    } catch {
        // Kein Audio verfügbar: still feiern
    }
}

type Particle = {
    x: number;
    y: number;
    vx: number;
    vy: number;
    rot: number;
    vr: number;
    size: number;
    color: string;
    shape: 0 | 1 | 2;
    life: number;
};

function burst(x: number, y: number, count: number, spread: number) {
    const canvas = document.createElement('canvas');
    const dpr = window.devicePixelRatio || 1;
    canvas.width = window.innerWidth * dpr;
    canvas.height = window.innerHeight * dpr;
    canvas.setAttribute('aria-hidden', 'true');
    Object.assign(canvas.style, { position: 'fixed', inset: '0', width: '100vw', height: '100vh', pointerEvents: 'none', zIndex: '60' });
    document.body.appendChild(canvas);
    const ctx = canvas.getContext('2d')!;
    ctx.scale(dpr, dpr);

    const parts: Particle[] = Array.from({ length: count }, () => {
        const angle = -Math.PI / 2 + (Math.random() - 0.5) * spread;
        const speed = 6 + Math.random() * 7;
        return {
            x,
            y,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            rot: Math.random() * Math.PI,
            vr: (Math.random() - 0.5) * 0.4,
            size: 6 + Math.random() * 6,
            color: COLORS[Math.floor(Math.random() * COLORS.length)],
            shape: Math.floor(Math.random() * 3) as 0 | 1 | 2,
            life: 1,
        };
    });

    let last = performance.now();
    function frame(t: number) {
        const dt = Math.min(2, (t - last) / 16.7);
        last = t;
        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
        let alive = 0;
        for (const p of parts) {
            if (p.life <= 0) continue;
            p.vy += 0.28 * dt;
            p.vx *= 0.985;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.rot += p.vr * dt;
            p.life -= 0.009 * dt;
            if (p.y > window.innerHeight + 20) p.life = 0;
            if (p.life <= 0) continue;
            alive++;
            ctx.save();
            ctx.globalAlpha = Math.min(1, p.life * 2);
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rot);
            ctx.fillStyle = p.color;
            if (p.shape === 0) ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
            else if (p.shape === 1) {
                ctx.beginPath();
                ctx.arc(0, 0, p.size / 2.6, 0, Math.PI * 2);
                ctx.fill();
            } else star(ctx, p.size / 1.8);
            ctx.restore();
        }
        if (alive) requestAnimationFrame(frame);
        else canvas.remove();
    }
    requestAnimationFrame(frame);
}

function star(ctx: CanvasRenderingContext2D, r: number) {
    ctx.beginPath();
    for (let i = 0; i < 10; i++) {
        const rad = i % 2 ? r * 0.45 : r;
        const a = (i * Math.PI) / 5 - Math.PI / 2;
        ctx.lineTo(Math.cos(a) * rad, Math.sin(a) * rad);
    }
    ctx.closePath();
    ctx.fill();
}

/** Schwebende Zahl wie „+12 XP“ über dem angeklickten Element. */
function floatText(x: number, y: number, text: string, still: boolean) {
    const el = document.createElement('div');
    el.textContent = text;
    el.setAttribute('aria-hidden', 'true');
    Object.assign(el.style, {
        position: 'fixed',
        left: `${x}px`,
        top: `${y}px`,
        transform: 'translate(-50%, -50%)',
        zIndex: '61',
        pointerEvents: 'none',
        font: '800 1.25rem/1 ui-rounded, system-ui, sans-serif',
        color: '#f59e0b',
        textShadow: '0 2px 0 #fff, 0 3px 8px rgba(0,0,0,.25)',
        transition: 'transform 1.1s cubic-bezier(.2,.8,.2,1), opacity 1.1s ease-in',
    });
    document.body.appendChild(el);
    requestAnimationFrame(() => {
        el.style.transform = still ? 'translate(-50%, -50%)' : 'translate(-50%, -160%) scale(1.15)';
        el.style.opacity = '0';
    });
    setTimeout(() => el.remove(), 1200);
}

export function useCelebrate() {
    const page = usePage<SharedData>();

    function settings(): Feedback {
        return page.props.auth?.feedback ?? { level: 'full', reduce_motion: false, sound: true, haptics: true };
    }

    /**
     * Feiert an der Position des Elements (oder der Bildschirmmitte).
     * `label` erscheint als schwebender Text, z. B. „Super!“.
     */
    function celebrate(kind: CelebrateKind = 'task', origin?: Element | null, label?: string) {
        if (typeof window === 'undefined') return;
        const s = settings();
        const systemReduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        const still = s.reduce_motion || systemReduced;

        const rect = origin?.getBoundingClientRect();
        const x = rect ? rect.left + rect.width / 2 : window.innerWidth / 2;
        const y = rect ? rect.top + rect.height / 2 : window.innerHeight / 2;

        if (s.haptics && 'vibrate' in navigator) navigator.vibrate(kind === 'step' ? 15 : kind === 'big' ? [20, 40, 20, 40, 60] : [20, 30, 40]);
        if (s.level === 'haptic') return;

        if (s.sound) playChime(kind, s.level === 'subtle');
        if (label) floatText(x, rect ? rect.top - 12 : y, label, still);
        if (still) return;

        const count = s.level === 'subtle' ? 14 : kind === 'step' ? 24 : kind === 'big' ? 160 : 70;
        const spread = kind === 'big' ? Math.PI * 1.6 : Math.PI * 0.9;
        burst(x, y, count, spread);
        if (kind === 'big' && s.level === 'full') {
            setTimeout(() => burst(window.innerWidth * 0.2, window.innerHeight * 0.9, 60, Math.PI * 0.6), 180);
            setTimeout(() => burst(window.innerWidth * 0.8, window.innerHeight * 0.9, 60, Math.PI * 0.6), 320);
        }
    }

    return { celebrate };
}

const PRAISE = ['Super!', 'Stark!', 'Geschafft!', 'Klasse!', 'Weiter so!', 'Juhu!', 'Spitze!', 'Toll gemacht!'];
export function praise(): string {
    return PRAISE[Math.floor(Math.random() * PRAISE.length)];
}
