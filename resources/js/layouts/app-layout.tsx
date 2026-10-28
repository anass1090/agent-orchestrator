import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { index as connections } from '@/routes/connections';
import { index as projects } from '@/routes/projects';
import { index as runs } from '@/routes/runs';

export default function AppLayout({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    const { status } = usePage().props;

    return (
        <>
            <Head title={title} />
            <header className="flex items-center gap-6 border-b px-6 py-3 text-sm">
                <strong className="mr-4">Pipeline</strong>
                <Link href={runs()} className="hover:underline">
                    Runs
                </Link>
                <Link href={projects()} className="hover:underline">
                    Projecten
                </Link>
                <Link href={connections()} className="hover:underline">
                    Connecties
                </Link>
            </header>

            <main className="mx-auto max-w-6xl space-y-6 p-6">
                {status && (
                    <p
                        role="status"
                        className="rounded-md border border-l-4 border-l-blue-600 px-3 py-2 text-sm"
                    >
                        {status}
                    </p>
                )}

                {children}
            </main>
        </>
    );
}
