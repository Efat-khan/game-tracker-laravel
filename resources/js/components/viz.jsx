import { useChartTheme } from '../lib/charts';
import { money } from '../lib/format';

/**
 * A donut with its legend.
 *
 * The legend is not decoration: on the light surface one of the three hues sits
 * below 3:1 against white, so the palette is only legal with visible labels
 * carrying the same information. Identity is never colour alone here.
 */
export function Donut({ segments = [], size = 168, thickness = 18, centreLabel, centreValue }) {
    const theme = useChartTheme();

    const total = segments.reduce((sum, s) => sum + Math.max(0, Number(s.value) || 0), 0);
    const radius = (size - thickness) / 2;
    const circumference = 2 * Math.PI * radius;

    let offset = 0;

    return (
        <div>
            <div className="relative mx-auto" style={{ width: size, height: size }}>
                <svg width={size} height={size} className="-rotate-90" role="img" aria-label={centreLabel}>
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke={theme.empty}
                        strokeWidth={thickness}
                    />

                    {total > 0 &&
                        segments.map((segment, index) => {
                            const value = Math.max(0, Number(segment.value) || 0);
                            if (value <= 0) return null;

                            const length = (value / total) * circumference;
                            // A 2px gap between fills, per the mark spec.
                            const dash = `${Math.max(0, length - 2)} ${circumference - Math.max(0, length - 2)}`;
                            const rotation = -offset;
                            offset += length;

                            return (
                                <circle
                                    key={segment.label}
                                    cx={size / 2}
                                    cy={size / 2}
                                    r={radius}
                                    fill="none"
                                    stroke={theme.categorical[index % theme.categorical.length]}
                                    strokeWidth={thickness}
                                    strokeLinecap="round"
                                    strokeDasharray={dash}
                                    strokeDashoffset={rotation}
                                />
                            );
                        })}
                </svg>

                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                        {centreLabel}
                    </span>
                    <span className="mt-0.5 text-lg font-bold tabular-nums">{centreValue}</span>
                </div>
            </div>

            <ul className="mt-5 space-y-2.5">
                {segments.map((segment, index) => (
                    <li key={segment.label} className="flex items-center gap-2.5 text-xs">
                        <span
                            className="h-0.5 w-5 shrink-0 rounded-full"
                            style={{ background: theme.categorical[index % theme.categorical.length] }}
                            aria-hidden="true"
                        />
                        <span className="flex-1 truncate text-slate-500 dark:text-slate-400">{segment.label}</span>
                        <span className="shrink-0 font-semibold tabular-nums">{money(segment.value)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** A thin progress track — used for utilization and for time against a plan. */
export function Meter({ percent, tone = 'indigo', className = '' }) {
    const tones = {
        indigo: 'bg-indigo-500',
        live: 'bg-live-500',
        amber: 'bg-amber-500',
        rose: 'bg-rose-500',
    };

    return (
        <div className={`h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800 ${className}`}>
            <div
                className={`h-full rounded-full transition-all ${tones[tone]}`}
                style={{ width: `${Math.max(0, Math.min(100, percent))}%` }}
            />
        </div>
    );
}
