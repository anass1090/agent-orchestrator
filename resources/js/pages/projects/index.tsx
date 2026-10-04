import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { usd } from '@/lib/format';
import { store } from '@/routes/projects';
import { index as runsIndex } from '@/routes/runs';
import type { Project } from '@/types';

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    return (
        <AppLayout title="Projecten">
            <h1 className="text-2xl font-semibold">Projecten</h1>

            {projects.length === 0 ? (
                <p className="text-muted-foreground">Nog geen projecten.</p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Naam</TableHead>
                            <TableHead>Pad</TableHead>
                            <TableHead>Branch</TableHead>
                            <TableHead>Remote</TableHead>
                            <TableHead className="text-right">Runs</TableHead>
                            <TableHead>Geslaagd</TableHead>
                            <TableHead className="text-right">Kosten</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {projects.map((project) => {
                            const finished =
                                project.succeeded_count +
                                project.failed_count +
                                project.error_count;

                            return (
                                <TableRow key={project.id}>
                                    <TableCell>
                                        <Link
                                            href={runsIndex({
                                                query: { project: project.id },
                                            })}
                                            className="underline"
                                        >
                                            {project.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {project.path}
                                    </TableCell>
                                    <TableCell>{project.branch}</TableCell>
                                    <TableCell>
                                        {project.remote ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {project.runs_count}
                                    </TableCell>
                                    <TableCell>
                                        {finished > 0 ? (
                                            <>
                                                {Math.round(
                                                    (project.succeeded_count /
                                                        finished) *
                                                        100,
                                                )}
                                                % geslaagd{' '}
                                                <span className="text-muted-foreground">
                                                    ({project.succeeded_count}{' '}
                                                    geslaagd,{' '}
                                                    {project.failed_count}{' '}
                                                    gefaald,{' '}
                                                    {project.error_count} fout)
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                –
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {usd(project.cost_usd)}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            )}

            <h2 className="pt-4 text-xl font-semibold">Project registreren</h2>
            <RegisterForm />
        </AppLayout>
    );
}

function RegisterForm() {
    const { data, setData, submit, processing, errors } = useForm({
        name: '',
        path: '',
        branch: 'main',
        remote: 'origin',
    });

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit(store());
    };

    const fields = [
        { key: 'name', label: 'Naam', required: true },
        { key: 'path', label: 'Pad naar de git-repository', required: true },
        { key: 'branch', label: 'Basisbranch', required: true },
        {
            key: 'remote',
            label: 'Remote (leeg = niet pushen)',
            required: false,
        },
    ] as const;

    return (
        <form onSubmit={onSubmit} className="grid max-w-xl gap-4">
            {fields.map(({ key, label, required }) => (
                <div key={key} className="grid gap-2">
                    <Label htmlFor={key}>{label}</Label>
                    <Input
                        id={key}
                        required={required}
                        value={data[key]}
                        onChange={(event) => setData(key, event.target.value)}
                    />
                    {errors[key] && (
                        <p className="text-sm text-destructive">
                            {errors[key]}
                        </p>
                    )}
                </div>
            ))}
            <div>
                <Button type="submit" disabled={processing}>
                    Registreren
                </Button>
            </div>
        </form>
    );
}
