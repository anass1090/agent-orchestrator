import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { destroy, index, live as liveRoute, stop } from '@/routes/runs';
import type { Run, RunEvent, Step } from '@/types';

type Props = {
    run: Run;
    events: RunEvent[];
    lastSequence: number;
    files: { path: string; url: string }[];
};

export default function RunShow(props: Props) {
    const { files } = props;
    const [run, setRun] = useState(props.run);

    useEffect(() => {
        setRun(props.run);
    }, [props.run]);

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
                    liveRoute.url(props.run.id, { query: { after } }),
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

                after = data.last_sequence;
                setRun(data.run);

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
    }, [props.run.busy, props.run.id, props.lastSequence]);

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
                    {run.busy && (
                        <span className="text-blue-700 dark:text-blue-400">
                            live
                        </span>
                    )}
                    {run.can_stop && (
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => router.post(stop.url(run.id))}
                        >
                            Run stoppen
                        </Button>
                    )}
                    {run.can_delete && (
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => {
                                if (confirm(`Run #${run.id} verwijderen?`)) {
                                    router.delete(destroy(run.id).url);
                                }
                            }}
                        >
                            Run verwijderen
                        </Button>
                    )}
                </div>
            </div>

            <PhaseStrip steps={run.steps} />

            <div className="flex flex-wrap gap-8">
                <section className="min-w-60 flex-1 space-y-3">
                    <h2 className="text-xl font-semibold">Samenvatting</h2>
                    <Summary run={run} />
                </section>

                <section className="min-w-60 flex-1 space-y-3">
                    <h2 className="text-xl font-semibold">Fases</h2>
                    <Steps steps={run.steps} />
                </section>

                {files.length > 0 && (
                    <section className="min-w-60 flex-1 space-y-3">
                        <h2 className="text-xl font-semibold">Bestanden</h2>
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
                    </section>
                )}
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
function PhaseStrip({ steps }: { steps: Step[] }) {
    if (steps.length === 0) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label="Fases"
            className="flex h-10 gap-px overflow-hidden rounded-md"
        >
            {steps.map((step) => {
                const label =
                    step.phase + (step.attempt > 0 ? ` ${step.attempt}` : '');

                return (
                    <div
                        key={step.id}
                        title={`${label} · ${step.status} · ${step.duration}`}
                        style={{
                            flexGrow: Math.max(
                                1,
                                Math.floor(step.duration_ms / 1000),
                            ),
                        }}
                        className={cn(
                            'flex min-w-12 basis-0 flex-col items-start justify-center overflow-hidden px-2 text-left text-xs leading-tight whitespace-nowrap text-white',
                            stepTones[step.status],
                        )}
                    >
                        <span>{label}</span>
                        <span className="opacity-80">{step.duration}</span>
                    </div>
                );
            })}
        </div>
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
        ['Duur', run.duration],
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
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
