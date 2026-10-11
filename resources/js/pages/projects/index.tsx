import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import AppLayout from '@/layouts/app-layout';
import { usd } from '@/lib/format';
import { index as connectionsIndex } from '@/routes/connections';
import { repositories as repositoriesRoute } from '@/routes/github';
import { destroy, storeFromGithub } from '@/routes/projects';
import { index as runsIndex } from '@/routes/runs';
import type { GithubConnection, GithubRepository, Project } from '@/types';

type Props = {
    projects: Project[];
    github: GithubConnection | null;
};

export default function ProjectsIndex({ projects, github }: Props) {
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
                            <TableHead />
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
                                    <TableCell className="text-right">
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        `Project ${project.name} verwijderen, inclusief al zijn runs?`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        destroy(project.id).url,
                                                    );
                                                }
                                            }}
                                        >
                                            Verwijderen
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            )}

            <h2 className="pt-4 text-xl font-semibold">Vanuit GitHub</h2>
            <GithubRegisterForm github={github} />
        </AppLayout>
    );
}

function GithubRegisterForm({ github }: { github: GithubConnection | null }) {
    const [repositories, setRepositories] = useState<GithubRepository[] | null>(
        null,
    );
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        if (!github) {
            return;
        }

        fetch(repositoriesRoute.url(), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then(setRepositories)
            .catch(() =>
                setLoadError('Repositories konden niet geladen worden.'),
            );
    }, [github]);

    const { data, setData, submit, processing, errors } = useForm({
        full_name: '',
        branch: 'main',
        remote: 'origin',
    });

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit(storeFromGithub());
    };

    const selectRepository = (fullName: string) => {
        const repository = repositories?.find(
            (candidate) => candidate.full_name === fullName,
        );

        if (!repository) {
            return;
        }

        setData({
            full_name: repository.full_name,
            branch: repository.default_branch,
            remote: data.remote,
        });
    };

    if (!github) {
        return (
            <p className="text-sm text-muted-foreground">
                <Link href={connectionsIndex()} className="underline">
                    Connect GitHub
                </Link>{' '}
                om ook private repositories te kunnen registreren.
            </p>
        );
    }

    return (
        <form onSubmit={onSubmit} className="grid max-w-xl gap-4">
            <div className="grid gap-2">
                <Label htmlFor="repository">Repository</Label>
                <Select value={data.full_name} onValueChange={selectRepository}>
                    <SelectTrigger id="repository">
                        <SelectValue placeholder="Kies een repository" />
                    </SelectTrigger>
                    <SelectContent>
                        {repositories?.map((repository) => (
                            <SelectItem
                                key={repository.full_name}
                                value={repository.full_name}
                            >
                                {repository.full_name}
                                {repository.private ? ' (privé)' : ''}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {errors.full_name && (
                    <p className="text-sm text-destructive">
                        {errors.full_name}
                    </p>
                )}
            </div>
            <div className="grid gap-2">
                <Label htmlFor="github-branch">Basisbranch</Label>
                <Input
                    id="github-branch"
                    required
                    value={data.branch}
                    onChange={(event) => setData('branch', event.target.value)}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="github-remote">
                    Remote (leeg = niet pushen)
                </Label>
                <Input
                    id="github-remote"
                    value={data.remote}
                    onChange={(event) => setData('remote', event.target.value)}
                />
            </div>
            {loadError && (
                <p className="text-sm text-destructive">{loadError}</p>
            )}
            <div>
                <Button
                    type="submit"
                    disabled={processing || data.full_name === ''}
                >
                    Registreren
                </Button>
            </div>
        </form>
    );
}
