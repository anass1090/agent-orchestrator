import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { connect, disconnect } from '@/routes/github';
import type { GithubConnection } from '@/types';

export default function GithubConnectionCard({
    connection,
}: {
    connection: GithubConnection | null;
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
                ) : (
                    <Button asChild>
                        <a href={connect.url()}>Connect met GitHub</a>
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}
