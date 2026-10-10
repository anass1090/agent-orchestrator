import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { connect, credentials, disconnect } from '@/routes/github';
import type { GithubConnection } from '@/types';

export default function GithubConnectionCard({
    connection,
    configured,
}: {
    connection: GithubConnection | null;
    configured: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>GitHub</CardTitle>
                <CardDescription>
                    Koppel je GitHub-account om ook private repositories te
                    kunnen registreren.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {connection ? (
                    <ConnectedState connection={connection} />
                ) : configured ? (
                    <Button asChild>
                        <a href={connect.url()}>Connect met GitHub</a>
                    </Button>
                ) : (
                    <CredentialsSetup />
                )}
            </CardContent>
        </Card>
    );
}

function ConnectedState({ connection }: { connection: GithubConnection }) {
    return (
        <>
            <p className="flex items-center gap-2 text-sm">
                {connection.avatar_url && (
                    <img
                        src={connection.avatar_url}
                        alt=""
                        className="size-6 rounded-full"
                    />
                )}
                Verbonden als <strong>{connection.username}</strong>
            </p>
            <Button
                variant="outline"
                onClick={() => router.post(disconnect.url())}
            >
                Loskoppelen
            </Button>
        </>
    );
}

function CredentialsSetup() {
    const callbackUrl = `${window.location.origin}/github/callback`;

    const { data, setData, submit, processing, errors } = useForm({
        client_id: '',
        client_secret: '',
    });

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit(credentials());
    };

    return (
        <div className="space-y-4">
            <ol className="list-decimal space-y-1 pl-4 text-sm text-muted-foreground">
                <li>
                    Ga naar{' '}
                    <a
                        href="https://github.com/settings/developers"
                        target="_blank"
                        rel="noreferrer"
                        className="underline"
                    >
                        github.com/settings/developers
                    </a>{' '}
                    → "New OAuth App".
                </li>
                <li>
                    Homepage URL:{' '}
                    <code className="font-mono">{window.location.origin}</code>
                </li>
                <li>
                    Authorization callback URL:{' '}
                    <code className="font-mono">{callbackUrl}</code>
                </li>
                <li>
                    Kopieer de Client ID, en klik op "Generate a new client
                    secret" voor de secret.
                </li>
            </ol>

            <form onSubmit={onSubmit} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="client_id">Client ID</Label>
                    <Input
                        id="client_id"
                        required
                        value={data.client_id}
                        onChange={(event) =>
                            setData('client_id', event.target.value)
                        }
                    />
                    {errors.client_id && (
                        <p className="text-sm text-destructive">
                            {errors.client_id}
                        </p>
                    )}
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="client_secret">Client Secret</Label>
                    <Input
                        id="client_secret"
                        type="password"
                        required
                        value={data.client_secret}
                        onChange={(event) =>
                            setData('client_secret', event.target.value)
                        }
                    />
                    {errors.client_secret && (
                        <p className="text-sm text-destructive">
                            {errors.client_secret}
                        </p>
                    )}
                </div>
                <div>
                    <Button type="submit" disabled={processing}>
                        Opslaan
                    </Button>
                </div>
            </form>
        </div>
    );
}
