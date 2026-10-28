import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const tones: Record<string, string> = {
    pending: 'border-amber-600 text-amber-700 dark:text-amber-400',
    paused: 'border-amber-600 text-amber-700 dark:text-amber-400',
    running: 'border-blue-600 text-blue-700 dark:text-blue-400',
    succeeded: 'border-green-600 text-green-700 dark:text-green-400',
    failed: 'border-red-600 text-red-700 dark:text-red-400',
    error: 'border-purple-600 text-purple-700 dark:text-purple-400',
    aborted: 'border-red-600 text-red-700 dark:text-red-400',
    skipped: 'border-muted-foreground text-muted-foreground',
};

const labels: Record<string, string> = {
    pending: 'wachtend',
    paused: 'gepauzeerd',
    running: 'loopt',
    succeeded: 'geslaagd',
    failed: 'mislukt',
    error: 'fout',
    aborted: 'afgebroken',
    skipped: 'overgeslagen',
};

export default function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant="outline" className={cn(tones[status])}>
            {labels[status] ?? status}
        </Badge>
    );
}
