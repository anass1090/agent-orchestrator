import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { number, usd } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    index,
    live as liveRoute,
    phase as phaseRoute,
    show,
    stop,
} from '@/routes/runs';
import { payload } from '@/routes/runs/events';
import type { Run, RunEvent, Step } from '@/types';

type Props = {
    run: Run;
    events: RunEvent[];
    lastSequence: number;
    showAll: boolean;
    phases: string[];
    documents: { label: string; html: string }[];
    files: { path: string; url: string }[];
};

/** Events and run state fetched since the page props were loaded. */
type Live = { base: RunEvent[]; run: Run; events: RunEvent[] };

const ALL = 'all';

export default function RunShow(props: Props) {
    const { showAll, phases, documents, files } = props;
    const [live, setLive] = useState<Live | null>(null);
    const [activePhase, setActivePhase] = useState<string | null>(null);

    // Live state only applies to the props it was fetched on top of.
    const current = live?.base === props.events ? live : null;
    const run = current?.run ?? props.run;
    const events = current
        ? [...props.events, ...current.events]
        : props.events;

    useEffect(() => {
        if (!props.run.busy) {
            return;
        }

        let after = props.lastSequence;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout>;

        const poll = async () => {
            try {
                const response = await fetch(
                    liveRoute.url(props.run.id, {
                        query: { after, all: showAll ? 1 : 0 },
                    }),
                    { headers: { Accept: 'application/json' } },
                );
                const data: {
                    run: Run;
                    last_sequence: number;
                    events: RunEvent[];
                } = await response.json();

                if (cancelled) {
                    return;
                }

                const follow =
                    window.innerHeight + window.scrollY >=
                    document.body.scrollHeight - 120;
                after = data.last_sequence;
                setLive((previous) => ({
                    base: props.events,
                    run: data.run,
                    events: [
                        ...(previous?.base === props.events
                            ? previous.events
                            : []),
                        ...data.events,
                    ],
                }));

                if (follow && data.events.length > 0) {
                    requestAnimationFrame(() =>
                        window.scrollTo({ top: document.body.scrollHeight }),
                    );
                }

                if (!data.run.busy) {
                    // Finished: reload once for plan, files and phase actions.
                    router.reload();

                    return;
                }
            } catch {
                // Transient failure (server restarting); keep polling.
            }

            if (!cancelled) {
                timer = setTimeout(poll, 2000);
            }
        };

        timer = setTimeout(poll, 2000);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [
        props.run.busy,
        props.run.id,
        props.events,
        props.lastSequence,
        showAll,
    ]);

    const stepPhases = [...new Set(run.steps.map((step) => step.phase))];
    const visible = activePhase
        ? events.filter((event) => event.phase === activePhase)
        : events;

    return (
        <AppLayout title={`Run #${run.id}`}>
            <div className="space-y-3">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    ← Runs
                </Link>
                <h1 className="text-2xl font-semibold">Run #{run.id}</h1>
                <p className="whitespace-pre-line">{run.prompt}</p>

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    {run.can_stop ? (
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => router.post(stop.url(run.id))}
                        >
                            Run stoppen
                        </Button>
                    ) : (
                        phases.length > 0 && (
                            <>
                                <span>Fase draaien:</span>
                                {phases.map((phase) => (
                                    <Button
                                        key={phase}
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                phaseRoute.url({
                                                    run: run.id,
                                                    phase,
                                                }),
                                            )
                                        }
                                    >
                                        {phase}
                                    </Button>
                                ))}
                            </>
                        )
                    )}
                </div>
            </div>

            <PhaseStrip
                steps={run.steps}
                active={activePhase}
                onSelect={(phase) =>
                    setActivePhase(activePhase === phase ? null : phase)
                }
            />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <section className="space-y-3">
                    <h2 className="text-xl font-semibold">Events</h2>
                    <div className="flex items-center gap-4 text-sm">
                        <Select
                            value={activePhase ?? ALL}
                            onValueChange={(value) =>
                                setActivePhase(value === ALL ? null : value)
                            }
                        >
                            <SelectTrigger size="sm">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Alle fases</SelectItem>
                                {stepPhases.map((phase) => (
                                    <SelectItem key={phase} value={phase}>
                                        {phase}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Link
                            href={show(run.id, {
                                query: { all: showAll ? 0 : 1 },
                            })}
                            className="underline"
                        >
                            {showAll ? 'Ruis verbergen' : 'Ook ruis tonen'}
                        </Link>
                        {run.busy && (
                            <span className="text-blue-700 dark:text-blue-400">
                                live
                            </span>
                        )}
                    </div>
                    <ol className="space-y-px font-mono text-xs">
                        {visible.map((event) => (
                            <EventItem
                                key={event.sequence}
                                runId={run.id}
                                event={event}
                            />
                        ))}
                    </ol>
                </section>

                <aside className="space-y-3">
                    <h2 className="text-xl font-semibold">Samenvatting</h2>
                    <Summary run={run} />

                    <h2 className="pt-3 text-xl font-semibold">Fases</h2>
                    <Steps steps={run.steps} />

                    {documents.map((document) => (
                        <div key={document.label} className="space-y-2 pt-3">
                            <h2 className="text-xl font-semibold">
                                {document.label}
                            </h2>
                            <div
                                className="text-sm [&_code]:font-mono [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded [&_pre]:bg-muted [&_pre]:p-2 [&_ul]:list-disc [&_ul]:pl-5"
                                // Rendered server-side with HTML input escaped.
                                dangerouslySetInnerHTML={{
                                    __html: document.html,
                                }}
                            />
                        </div>
                    ))}

                    {files.length > 0 && (
                        <>
                            <h2 className="pt-3 text-xl font-semibold">
                                Bestanden
                            </h2>
                            <ul className="space-y-1">
                                {files.map((file) => (
                                    <li key={file.path}>
                                        <a
                                            href={file.url}
                                            target="_blank"
                                            rel="noopener"
                                            className="font-mono text-xs underline"
                                        >
                                            {file.path}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </aside>
            </div>
        </AppLayout>
    );
}

const stepTones: Record<string, string> = {
    running: 'bg-blue-600',
    succeeded: 'bg-green-600',
    failed: 'bg-red-600',
    error: 'bg-purple-600',
    skipped: 'bg-muted-foreground/40',
};

/** Each phase attempt as wide as it took; colour is its outcome. */
function PhaseStrip({
    steps,
    active,
    onSelect,
}: {
    steps: Step[];
    active: string | null;
    onSelect: (phase: string) => void;
}) {
    if (steps.length === 0) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label="Fases, klik om de events te filteren"
            className="flex h-10 gap-px overflow-hidden rounded-md"
        >
            {steps.map((step) => {
                const label =
                    step.phase + (step.attempt > 0 ? ` ${step.attempt}` : '');

                return (
                    <button
                        key={step.id}
                        type="button"
                        aria-pressed={active === step.phase}
                        title={`${label} · ${step.status} · ${step.duration}`}
                        onClick={() => onSelect(step.phase)}
                        style={{
                            flexGrow: Math.max(
                                1,
                                Math.floor(step.duration_ms / 1000),
                            ),
                        }}
                        className={cn(
                            'flex min-w-12 basis-0 flex-col items-start justify-center overflow-hidden px-2 text-left text-xs leading-tight whitespace-nowrap text-white',
                            stepTones[step.status],
                            active && active !== step.phase && 'opacity-40',
                        )}
                    >
                        <span>{label}</span>
                        <span className="opacity-80">{step.duration}</span>
                    </button>
                );
            })}
        </div>
    );
}

const eventTones: Record<string, string> = {
    phase: 'font-semibold text-fuchsia-700 dark:text-fuchsia-400',
    error: 'text-red-700 dark:text-red-400',
    'check-failed': 'text-red-700 dark:text-red-400',
    'tool-error': 'text-red-700 dark:text-red-400',
    'result-error': 'text-red-700 dark:text-red-400',
    'check-passed': 'text-green-700 dark:text-green-400',
    'result-ok': 'text-green-700 dark:text-green-400',
    warning: 'text-amber-700 dark:text-amber-400',
    command: 'text-muted-foreground',
    'tool-ok': 'text-muted-foreground',
    thinking: 'text-muted-foreground',
    other: 'text-muted-foreground',
};

/** One event; its payload loads on first open. */
function EventItem({ runId, event }: { runId: number; event: RunEvent }) {
    const [content, setContent] = useState<string | null>(null);

    const load = async (open: boolean) => {
        if (!open || content !== null) {
            return;
        }

        setContent('Laden…');

        try {
            const response = await fetch(
                payload.url({ run: runId, event: event.sequence }),
                { headers: { Accept: 'application/json' } },
            );
            setContent(JSON.stringify(await response.json(), null, 2));
        } catch (error) {
            setContent(null);
            console.error('Payload kon niet geladen worden', error);
        }
    };

    const text =
        (event.tool && event.kind !== 'tool-use' ? event.tool + ' ' : '') +
        (event.summary ?? event.type) +
        (event.payload_on_disk ? ' (payload op schijf)' : '');

    return (
        <li className={eventTones[event.kind]}>
            <details
                onToggle={(e) => void load(e.currentTarget.open)}
                className="rounded px-1 hover:bg-muted/60"
            >
                <summary className="flex cursor-pointer gap-3 py-0.5">
                    <span
                        className="shrink-0 text-muted-foreground"
                        title={event.time}
                    >
                        {event.offset}
                    </span>
                    <span className="w-14 shrink-0 text-muted-foreground">
                        {event.phase}
                    </span>
                    <span className="break-all whitespace-pre-wrap">
                        {event.glyph} {text}
                    </span>
                </summary>
                <pre className="my-1 max-h-96 overflow-auto rounded bg-muted p-2 text-foreground">
                    {content}
                </pre>
            </details>
        </li>
    );
}

function Summary({ run }: { run: Run }) {
    const facts: [string, string][] = [
        ['Project', run.project.name],
        [
            'Branch',
            (run.branch ?? '–') +
                (run.pushed ? ' (gepusht)' : '') +
                (run.merged ? ', gemerged' : ''),
        ],
        ['Model', run.model ?? '–'],
        ['Fixpogingen', `${run.fix_attempts} / ${run.max_fix_attempts}`],
        ['Duur', run.duration],
        ['Kosten', usd(run.cost_usd, 4)],
        [
            'Tokens in / uit',
            `${number(run.tokens_in)} / ${number(run.tokens_out)}`,
        ],
        ['Gestart', run.started_at ?? 'nog niet'],
    ];

    return (
        <div className="space-y-2 text-sm">
            <p className="space-x-1">
                <StatusBadge status={run.status} />
                {run.failed_check && (
                    <span>
                        · gefaald op <strong>{run.failed_check}</strong>
                    </span>
                )}
                {run.stop_reason && (
                    <span className="text-muted-foreground">
                        · {run.stop_reason}
                    </span>
                )}
            </p>
            {run.error && <p className="text-destructive">{run.error}</p>}
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                {facts.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

function Steps({ steps }: { steps: Step[] }) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Fase</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Duur</TableHead>
                    <TableHead className="text-right">Exit</TableHead>
                    <TableHead className="text-right">Kosten</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {steps.map((step) => (
                    <TableRow key={step.id}>
                        <TableCell>
                            {step.phase}
                            {step.attempt > 0 && ` #${step.attempt}`}
                        </TableCell>
                        <TableCell className="whitespace-normal">
                            <StatusBadge status={step.status} />
                            {step.error && (
                                <div className="line-clamp-3 text-xs text-destructive">
                                    {step.error}
                                </div>
                            )}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {step.duration}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {step.exit_code ?? '–'}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {step.cost_usd !== null && usd(step.cost_usd, 4)}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
