import ClaudeConnectionCard from '@/components/claude-connection-card';
import GithubConnectionCard from '@/components/github-connection-card';
import AppLayout from '@/layouts/app-layout';
import type { ClaudeStatus, GithubConnection } from '@/types';

type Props = {
    claude: ClaudeStatus;
    github: GithubConnection | null;
    githubConfigured: boolean;
};

export default function ConnectionsIndex({
    claude,
    github,
    githubConfigured,
}: Props) {
    return (
        <AppLayout title="Connections">
            <h1 className="text-2xl font-semibold">Connections</h1>

            <div className="grid gap-6 md:grid-cols-2">
                <ClaudeConnectionCard initialStatus={claude} />
                <GithubConnectionCard
                    connection={github}
                    configured={githubConfigured}
                />
            </div>
        </AppLayout>
    );
}
