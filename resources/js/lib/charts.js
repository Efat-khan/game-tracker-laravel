import { useEffect, useState } from 'react';

/**
 * Chart colours, kept out of the components so light and dark swap in one place.
 *
 * Every chart here plots ONE measure, so there is no categorical palette to get
 * wrong — one blue, stepped separately for each surface. The heatmap is the only
 * sequential encoding: a single hue, light to dark, where the lightest step means
 * "nobody was playing".
 *
 * Validated against the real card surfaces (#ffffff light, #0b1120 dark):
 * lightness band, chroma floor and 3:1 contrast all pass in both modes.
 */
export const CHART = {
    light: {
        series: '#2a78d6',
        grid: '#dbe1f2',
        axis: '#6d7da8',
        surface: '#ffffff',
        text: '#0f172a',
        // Sequential blue, 100 → 700. Lightest = near zero.
        ramp: ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#2a78d6', '#256abf', '#184f95', '#0d366b'],
        empty: '#eceffa',
    },
    dark: {
        series: '#3987e5',
        grid: '#151f3a',
        axis: '#8e9cc4',
        // The card surface: slate-900 at 70% over slate-950. The series is
        // re-validated against this, not against a generic dark grey.
        surface: '#0b1120',
        text: '#eceffa',
        ramp: ['#0d366b', '#104281', '#184f95', '#256abf', '#2a78d6', '#3987e5', '#5598e7', '#86b6ef'],
        empty: '#151f3a',
    },
};

/**
 * Tracks the dark class on <html>.
 *
 * The toggle lives in the sidebar, so a chart several components away would
 * never re-render on its own — this observes the attribute instead of relying
 * on shared state.
 */
export function useIsDark() {
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));

    useEffect(() => {
        const observer = new MutationObserver(() =>
            setDark(document.documentElement.classList.contains('dark')),
        );

        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return dark;
}

export function useChartTheme() {
    return CHART[useIsDark() ? 'dark' : 'light'];
}

/** Pick a ramp step for a 0–1 intensity. */
export function rampStep(ramp, intensity) {
    if (!Number.isFinite(intensity) || intensity <= 0) return null;
    const index = Math.min(ramp.length - 1, Math.floor(intensity * ramp.length));
    return ramp[index];
}
