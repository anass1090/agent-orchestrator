import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/csrf';
import { login, logout, status as statusRoute } from '@/routes/claude';
import { code as loginCode } from '@/routes/claude/login';
import type { ClaudeStatus } from '@/types';

const POLL_INTERVAL_MS = 2000;

export default function ClaudeConnectionCard({
    initialStatus,
}: {
    initialStatus: ClaudeStatus;
}) {
    const [status, setStatus] = useState(initialStatus);
    const [authorizeUrl, setAuthorizeUrl] = useState<string | null>(null);
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!authorizeUrl || status.loggedIn) {
            return;
        }

        let cancelled = false;

        const poll = async () => {
            const response = await fetch(statusRoute.url(), {
                headers: { Accept: 'application/json' },
            });
            const data: ClaudeStatus = await response.json();

            if (cancelled) {
                return;
            }

            setStatus(data);

            if (data.loggedIn) {
                setAuthorizeUrl(null);
            } else if (!data.attempt_alive) {
                setError('De inlogpoging is gestopt. Probeer het opnieuw.');
                setAuthorizeUrl(null);
            }
        };

        const timer = setInterval(poll, POLL_INTERVAL_MS);

        return () => {
            cancelled = true;
            clearInterval(timer);
        };
    }, [authorizeUrl, status.loggedIn]);

    const startLogin = async () => {
        setBusy(true);
        setError(null);

        try {
            const data = await postJson<{ authorize_url: string }>(login.url());
            setAuthorizeUrl(data.authorize_url);
        } catch {
            setError('Inloggen kon niet gestart worden.');
        } finally {
            setBusy(false);
        }
    };

    const submitCode = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await postJson(loginCode.url(), { code });
            setCode('');
        } catch {
            setError('Code kon niet verstuurd worden.');
        } finally {
            setBusy(false);
        }
    };

    const disconnectClaude = async () => {
        setBusy(true);

        try {
            await postJson(logout.url());
            setStatus({
                loggedIn: false,
                authMethod: 'none',
                attempt_alive: false,
            });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Claude Code</CardTitle>
                <CardDescription>
                    Nodig om de agent-fases van een run uit te voeren.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {status.loggedIn ? (
                    <>
                        <p className="text-sm">
                            Verbonden ({status.authMethod}).
                        </p>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={disconnectClaude}
                        >
                            Loskoppelen
                        </Button>
                    </>
                ) : authorizeUrl ? (
                    <form onSubmit={submitCode} className="space-y-3">
                        <p className="text-sm">
                            Open{' '}
                            <a
                                href={authorizeUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="underline"
                            >
                                deze link
                            </a>{' '}
                            om in te loggen, en plak de code die je terugkrijgt
                            hieronder.
                        </p>
                        <Input
                            value={code}
                            onChange={(event) => setCode(event.target.value)}
                            placeholder="Geplakte code"
                            required
                        />
                        <Button type="submit" disabled={busy || code === ''}>
                            Bevestigen
                        </Button>
                    </form>
                ) : (
                    <Button disabled={busy} onClick={startLogin}>
                        Connect met Claude Code
                    </Button>
                )}

                {error && <p className="text-sm text-destructive">{error}</p>}
            </CardContent>
        </Card>
    );
}
