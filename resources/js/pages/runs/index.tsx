import { Link, router, useForm, usePoll } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { index as projectsIndex } from '@/routes/projects';
import { destroy, index, show, store } from '@/routes/runs';
import type { Paginated, Run } from '@/types';

type Props = {
    runs: Paginated<Run>;
    projects: { id: number; name: string }[];
    statuses: string[];
    filters: { project: number | null; status: string | null };
};

const ALL = 'all';

export default function RunsIndex({
    runs,
    projects,
    statuses,
    filters,
}: Props) {
    // Reload the list while any run on it is busy.
    const busy = runs.data.some((run) => run.busy);
    const { start, stop } = usePoll(5000, {}, { autoStart: false });

    useEffect(() => {
        if (busy) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [busy, start, stop]);

    const filter = (key: 'project' | 'status', value: string) => {
        router.get(
            index({
                query: { ...filters, [key]: value === ALL ? null : value },
            }).url,
            {},
            { preserveState: true },
        );
    };

    return (
        <AppLayout title="Runs">
            <h1 className="text-2xl font-semibold">Nieuwe run</h1>

            {projects.length === 0 ? (
                <p>
                    Registreer eerst een project op de pagina{' '}
                    <Link href={projectsIndex()} className="underline">
                        Projecten
                    </Link>
                    .
                </p>
            ) : (
                <NewRunForm projects={projects} selected={filters.project} />
            )}

            <h2 className="pt-4 text-xl font-semibold">Runs</h2>

            <div className="flex gap-2">
                <Select
                    value={filters.project ? String(filters.project) : ALL}
                    onValueChange={(value) => filter('project', value)}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>Alle projecten</SelectItem>
                        {projects.map((project) => (
                            <SelectItem
                                key={project.id}
                                value={String(project.id)}
                            >
                                {project.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select
                    value={filters.status ?? ALL}
                    onValueChange={(value) => filter('status', value)}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>Alle statussen</SelectItem>
                        {statuses.map((status) => (
                            <SelectItem key={status} value={status}>
                                {status}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {runs.data.length === 0 ? (
                <p className="text-muted-foreground">Geen runs.</p>
            ) : (
                <>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Featureverzoek</TableHead>
                                <TableHead>Project</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Duur
                                </TableHead>
                                <TableHead>Gestart</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs.data.map((run) => (
                                <TableRow key={run.id}>
                                    <TableCell>
                                        <Link
                                            href={show(run.id)}
                                            className="underline"
                                        >
                                            {run.id}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="max-w-md truncate">
                                        <Link
                                            href={show(run.id)}
                                            className="hover:underline"
                                        >
                                            {run.prompt}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{run.project.name}</TableCell>
                                    <TableCell className="space-x-2">
                                        <StatusBadge status={run.status} />
                                        {run.failed_check && (
                                            <span className="text-muted-foreground">
                                                gefaald op {run.failed_check}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.duration}
                                    </TableCell>
                                    <TableCell>{run.created_at}</TableCell>
                                    <TableCell className="text-right">
                                        {run.can_delete && (
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => {
                                                    if (
                                                        confirm(
                                                            `Run #${run.id} verwijderen?`,
                                                        )
                                                    ) {
                                                        router.delete(
                                                            destroy(run.id).url,
                                                        );
                                                    }
                                                }}
                                            >
                                                Verwijderen
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>

                    <div className="flex gap-4">
                        {runs.links.prev && (
                            <Link href={runs.links.prev} className="underline">
                                Nieuwere
                            </Link>
                        )}
                        {runs.links.next && (
                            <Link href={runs.links.next} className="underline">
                                Oudere
                            </Link>
                        )}
                    </div>
                </>
            )}
        </AppLayout>
    );
}

function NewRunForm({
    projects,
    selected,
}: {
    projects: { id: number; name: string }[];
    selected: number | null;
}) {
    const { data, setData, submit, processing, errors } = useForm({
        project_id: String(selected ?? projects[0].id),
        prompt: '',
    });

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit(store());
    };

    return (
        <form onSubmit={onSubmit} className="grid max-w-2xl gap-4">
            <div className="grid gap-2">
                <Label htmlFor="project_id">Project</Label>
                <Select
                    value={data.project_id}
                    onValueChange={(value) => setData('project_id', value)}
                >
                    <SelectTrigger id="project_id">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {projects.map((project) => (
                            <SelectItem
                                key={project.id}
                                value={String(project.id)}
                            >
                                {project.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {errors.project_id && (
                    <p className="text-sm text-destructive">
                        {errors.project_id}
                    </p>
                )}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="prompt">Featureverzoek</Label>
                <Textarea
                    id="prompt"
                    rows={5}
                    required
                    minLength={10}
                    value={data.prompt}
                    onChange={(event) => setData('prompt', event.target.value)}
                />
                {errors.prompt && (
                    <p className="text-sm text-destructive">{errors.prompt}</p>
                )}
            </div>

            <div>
                <Button type="submit" disabled={processing}>
                    Run starten
                </Button>
            </div>
        </form>
    );
}
